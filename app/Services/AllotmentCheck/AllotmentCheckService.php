<?php

namespace App\Services\AllotmentCheck;

use App\Models\Ipo;
use App\Models\IpoApplication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
     * Fetch active company list from registrar with caching (15 minutes).
     * Returns array [company_client_id => company_name].
     *
     * @return array<string, string>
     */
    public function getRegistrarCompanies(string $registrar): array
    {
        return Cache::remember("registrar_companies:{$registrar}", 900, function () use ($registrar) {
            try {
                return match ($registrar) {
                    'mufg_intime' => $this->fetchMufgCompanies(),
                    'bigshare' => $this->fetchBigshareCompanies(),
                    default => [],
                };
            } catch (Throwable $e) {
                report($e);

                return [];
            }
        });
    }

    /**
     * Try to find matching company ID from registrar's live company list based on IPO name.
     */
    public function matchCompanyId(string $registrar, ?string $ipoName): ?string
    {
        if (! $ipoName) {
            return null;
        }

        $companies = $this->getRegistrarCompanies($registrar);
        $cleanIpo = preg_replace('/[^a-z0-9]/', '', strtolower($ipoName));

        if (empty($cleanIpo)) {
            return null;
        }

        foreach ($companies as $id => $name) {
            $cleanComp = preg_replace('/[^a-z0-9]/', '', strtolower($name));
            if (str_contains($cleanComp, $cleanIpo) || str_contains($cleanIpo, $cleanComp)) {
                return (string) $id;
            }
        }

        // Try matching first 2 significant words (e.g. "HD Fire" matching "HD Fire Protect Limited - IPO")
        $words = array_filter(explode(' ', strtolower(preg_replace('/[^a-z0-9\s]/', '', $ipoName))));
        $significant = array_slice(array_diff($words, ['ipo', 'limited', 'ltd', 'sme']), 0, 2);
        if (! empty($significant)) {
            $searchPhrase = implode('', $significant);
            foreach ($companies as $id => $name) {
                $cleanComp = preg_replace('/[^a-z0-9]/', '', strtolower($name));
                if (str_contains($cleanComp, $searchPhrase)) {
                    return (string) $id;
                }
            }
        }

        return null;
    }

    private function fetchMufgCompanies(): array
    {
        $response = Http::withHeaders([
            'accept' => 'application/json, text/javascript, */*; q=0.01',
            'content-type' => 'application/json; charset=UTF-8',
            'origin' => 'https://in.mpms.mufg.com',
            'referer' => 'https://in.mpms.mufg.com/Initial_Offer/public-issues.html',
            'x-requested-with' => 'XMLHttpRequest',
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        ])
            ->timeout(10)
            ->send('POST', 'https://in.mpms.mufg.com/Initial_Offer/IPO.aspx/GetDetails', ['body' => '']);

        $xml = $response->json('d');
        if (! $xml || ! str_contains($xml, '<Table>')) {
            return [];
        }

        $out = [];
        $doc = @simplexml_load_string($xml);
        if ($doc && isset($doc->Table)) {
            foreach ($doc->Table as $row) {
                $id = trim((string) $row->company_id);
                $name = trim((string) $row->companyname);
                if ($id !== '' && $name !== '') {
                    $out[$id] = $name;
                }
            }
        }

        return $out;
    }

    private function fetchBigshareCompanies(): array
    {
        $response = Http::withHeaders([
            'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        ])
            ->timeout(10)
            ->get('https://ipo.bigshareonline.com/ipo_status.aspx');

        $body = $response->body();
        if (! preg_match('/<select[^>]*ddlCompany[^>]*>(.*?)<\/select>/is', $body, $m)) {
            return [];
        }

        $out = [];
        preg_match_all('/<option[^>]*value=["\']?([^"\' >]+)["\']?[^>]*>(.*?)<\/option>/is', $m[1], $opts);
        foreach ($opts[1] as $idx => $id) {
            $name = trim(html_entity_decode($opts[2][$idx]));
            if ($id !== '' && $id !== '0' && ! str_contains($name, '--Select')) {
                $out[$id] = $name;
            }
        }

        return $out;
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
    public function checkDirect(Ipo $ipo, string $pan): array
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
                    ? 'Allotment is scheduled for '.$ipo->allotment_date->format('d M Y').'. Registrar has not declared results yet.'
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
                'message' => 'Registrar server error: '.$e->getMessage(),
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

    /**
     * Run allotment checks directly/synchronously for multiple applications.
     * Ideal for environments without background queue workers (e.g. Hostinger shared hosting).
     *
     * @param  iterable<IpoApplication>  $applications
     * @return array{
     *     total: int,
     *     allotted: int,
     *     refunded: int,
     *     not_found: int,
     *     skipped: int,
     *     error: int,
     *     errors: array<string>
     * }
     */
    public function checkManyDirect(iterable $applications): array
    {
        @set_time_limit(300);

        $counts = [
            'total' => 0,
            'allotted' => 0,
            'refunded' => 0,
            'not_found' => 0,
            'skipped' => 0,
            'error' => 0,
            'errors' => [],
        ];

        foreach ($applications as $application) {
            $counts['total']++;
            $outcome = $this->checkForApplication($application);

            if ($outcome->status === 'updated') {
                $fresh = $application->fresh();
                if (in_array($fresh?->status, ['allotted', 'partial_allotted'], true)) {
                    $counts['allotted']++;
                } else {
                    $counts['refunded']++;
                }
            } elseif ($outcome->status === 'not_found') {
                $counts['not_found']++;
            } elseif ($outcome->status === 'skipped') {
                $counts['skipped']++;
            } elseif ($outcome->status === 'error') {
                $counts['error']++;
                if ($outcome->message) {
                    $applicantName = $application->applicant?->name ?? "App #{$application->id}";
                    $counts['errors'][] = "{$applicantName}: {$outcome->message}";
                }
            }
        }

        return $counts;
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
        } elseif ($application->status === 'refunded' && $application->return_amount === null) {
            $application->return_amount = $application->amount;
        }

        if (in_array($application->status, ['refunded', 'partial_allotted'], true) && ! $application->return_date) {
            $application->return_date = now()->toDateString();
        }

        $note = '['.now()->toDateString().'] Auto allotment check: '
            .($result->sharesApplied !== null ? "applied {$result->sharesApplied}, " : '')
            ."allotted {$result->sharesAllotted}"
            .($result->refundReference ? ", refund ref {$result->refundReference}" : '').'.';

        $application->notes = trim(($application->notes ? $application->notes."\n" : '').$note);

        $application->save();
    }
}
