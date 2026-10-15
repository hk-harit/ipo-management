<?php

namespace App\Services\AllotmentCheck;

use App\Models\IpoApplication;
use Throwable;

/**
 * Runs one application's applicant PAN through whichever registrar its IPO is
 * configured for, and folds the answer back into the application — status,
 * shares allotted, amount returned — the same fields you'd fill in by hand
 * after checking the registrar's site yourself.
 */
class AllotmentCheckService
{
    /** @var array<string, class-string<AllotmentChecker>> */
    private const CHECKERS = [
        'mufg_intime' => MufgIntimeChecker::class,
        'bigshare' => BigshareChecker::class,
    ];

    /** Registrar options for the IPO form — kept next to CHECKERS so the two never drift apart. */
    public static function registrarOptions(): array
    {
        return [
            'mufg_intime' => 'MUFG Intime (Link Intime)',
            'bigshare' => 'Bigshare Services',
        ];
    }

    /**
     * Direct allotment lookup by IPO and PAN string.
     * Returns a structured array result suitable for API / public response.
     *
     * @return array{
     *     success: bool,
     *     status: 'allotted'|'partial_allotted'|'not_allotted'|'not_found'|'error'|'pending',
     *     is_allotted: bool,
     *     shares_applied: ?int,
     *     shares_allotted: int,
     *     refund_amount: ?float,
     *     refund_reference: ?string,
     *     company_name: ?string,
     *     message: string,
     *     registrar: ?string
     * }
     */
    public function checkDirect(\App\Models\Ipo $ipo, string $pan): array
    {
        $pan = strtoupper(trim($pan));

        if (! preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan)) {
            return [
                'success' => false,
                'status' => 'error',
                'is_allotted' => false,
                'shares_applied' => null,
                'shares_allotted' => 0,
                'refund_amount' => null,
                'refund_reference' => null,
                'company_name' => $ipo->name,
                'message' => 'Invalid PAN format. Please enter a valid 10-character PAN number.',
                'registrar' => $ipo->registrar,
            ];
        }

        if (! $ipo->registrar || ! $ipo->registrar_client_id) {
            // Check if allotment date is still in future
            $isFuture = $ipo->allotment_date && $ipo->allotment_date->isFuture();
            
            return [
                'success' => false,
                'status' => $isFuture ? 'pending' : 'not_found',
                'is_allotted' => false,
                'shares_applied' => null,
                'shares_allotted' => 0,
                'refund_amount' => null,
                'refund_reference' => null,
                'company_name' => $ipo->name,
                'message' => $isFuture 
                    ? 'Allotment is scheduled for ' . $ipo->allotment_date->format('d M Y') . '. Registrar has not declared results yet.'
                    : 'Registrar details not configured for this IPO or results not declared yet.',
                'registrar' => $ipo->registrar,
            ];
        }

        $checkerClass = self::CHECKERS[$ipo->registrar] ?? null;

        if (! $checkerClass) {
            return [
                'success' => false,
                'status' => 'error',
                'is_allotted' => false,
                'shares_applied' => null,
                'shares_allotted' => 0,
                'refund_amount' => null,
                'refund_reference' => null,
                'company_name' => $ipo->name,
                'message' => "No checker built for registrar [{$ipo->registrar}]",
                'registrar' => $ipo->registrar,
            ];
        }

        try {
            /** @var ?AllotmentCheckResult $result */
            $result = app($checkerClass)->check($ipo->registrar_client_id, $pan);
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'status' => 'error',
                'is_allotted' => false,
                'shares_applied' => null,
                'shares_allotted' => 0,
                'refund_amount' => null,
                'refund_reference' => null,
                'company_name' => $ipo->name,
                'message' => 'Registrar server error: ' . $e->getMessage(),
                'registrar' => $ipo->registrar,
            ];
        }

        if (! $result) {
            return [
                'success' => true,
                'status' => 'not_found',
                'is_allotted' => false,
                'shares_applied' => null,
                'shares_allotted' => 0,
                'refund_amount' => null,
                'refund_reference' => null,
                'company_name' => $ipo->name,
                'message' => 'No application record found for this PAN in this IPO.',
                'registrar' => $ipo->registrar,
            ];
        }

        $isAllotted = $result->isAllotted();
        $status = $isAllotted 
            ? ($result->isFullyAllotted() ? 'allotted' : 'partial_allotted')
            : 'not_allotted';

        return [
            'success' => true,
            'status' => $status,
            'is_allotted' => $isAllotted,
            'shares_applied' => $result->sharesApplied,
            'shares_allotted' => $result->sharesAllotted,
            'refund_amount' => $result->refundAmount,
            'refund_reference' => $result->refundReference,
            'company_name' => $result->companyName ?? $ipo->name,
            'message' => $isAllotted 
                ? "Congratulations! {$result->sharesAllotted} shares allotted."
                : 'Not allotted. Better luck in the next IPO!',
            'registrar' => $ipo->registrar,
        ];
    }

    public function checkForApplication(IpoApplication $application): AllotmentCheckOutcome
    {
        $ipo = $application->ipo;
        $applicant = $application->applicant;

        if (! $ipo?->registrar || ! $ipo->registrar_client_id) {
            return AllotmentCheckOutcome::skipped('IPO has no registrar configured');
        }

        if (! $applicant?->pan_number) {
            return AllotmentCheckOutcome::skipped('Applicant has no PAN on file');
        }

        $checkerClass = self::CHECKERS[$ipo->registrar] ?? null;

        if (! $checkerClass) {
            return AllotmentCheckOutcome::skipped("No checker built for registrar [{$ipo->registrar}]");
        }

        try {
            $result = app($checkerClass)->check($ipo->registrar_client_id, $applicant->pan_number);
        } catch (Throwable $e) {
            report($e);

            return AllotmentCheckOutcome::error($e->getMessage());
        }

        if (! $result) {
            return AllotmentCheckOutcome::notFound();
        }

        $this->applyResult($application, $result);

        return AllotmentCheckOutcome::updated();
    }

    private function applyResult(IpoApplication $application, AllotmentCheckResult $result): void
    {
        if ($result->isAllotted()) {
            $application->status = $result->isFullyAllotted() ? 'allotted' : 'partial_allotted';
            $application->shares_allotted = $result->sharesAllotted;
        } else {
            $application->status = 'refunded';
            $application->shares_allotted = null;
        }

        if ($result->refundAmount !== null && in_array($application->status, ['refunded', 'partial_allotted'], true)) {
            $application->return_amount = $result->refundAmount;
        }

        $note = '['.now()->toDateString().'] Auto allotment check: '
            .($result->sharesApplied !== null ? "applied {$result->sharesApplied}, " : '')
            ."allotted {$result->sharesAllotted}"
            .($result->refundReference ? ", refund ref {$result->refundReference}" : '').'.';

        $application->notes = trim(($application->notes ? $application->notes."\n" : '').$note);

        $application->save();
    }
}
