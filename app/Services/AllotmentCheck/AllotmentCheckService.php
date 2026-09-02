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
