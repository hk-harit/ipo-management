<?php

namespace App\Services\IpoFeeds;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * chittorgarh.com — the wide, free feed. No API key at all.
 *
 * Two of their endpoints are used, because each holds what the other lacks:
 *
 *  - The financial-year *report* is the backbone: every mainboard and SME issue
 *    of the year, with real ISO dates and a price band. It carries no lot size
 *    and no status.
 *  - The current-window *list* covers only the IPOs open right now, but adds
 *    the market lot size and an explicit status.
 *
 * The report is merged with the list by slug. If the list call fails the report
 * still stands on its own — lot sizes are worth having, not worth failing over.
 *
 * These are chittorgarh's internal endpoints, not a published API, so they can
 * change shape or stop answering without notice. Nothing here assumes a field
 * is present, and IpoSync isolates a failure of this feed from the others.
 */
class ChittorgarhFeed implements IpoFeed
{
    /** The id of chittorgarh's "IPO list" report. */
    private const REPORT_ID = 82;

    /** Their "IPO subscription / live bidding" report — how many times each category bid. */
    private const SUBSCRIPTION_REPORT_ID = 21;

    private const CATEGORIES = ['mainboard', 'sme'];

    public function __construct(
        private readonly bool $enabled = true,
        private readonly string $baseUrl = 'https://webnodejs.chittorgarh.com',
        private readonly string $siteUrl = 'https://www.chittorgarh.com',
        private readonly int $maxPages = 20,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            enabled: (bool) config('services.chittorgarh.enabled', true),
            baseUrl: rtrim((string) config('services.chittorgarh.base_url'), '/'),
            siteUrl: rtrim((string) config('services.chittorgarh.site_url'), '/'),
        );
    }

    public function key(): string
    {
        return 'chittorgarh';
    }

    public function label(): string
    {
        return 'chittorgarh.com';
    }

    public function isConfigured(): bool
    {
        return $this->enabled;
    }

    public function fetch(): array
    {
        $reportRows = [];

        foreach (self::CATEGORIES as $category) {
            foreach ($this->report($category) as $row) {
                $reportRows[] = $row;
            }
        }

        // Both extras are enrichment: worth having, not worth failing over.
        $extras = $this->mergeBySlug(
            $this->currentWindowSafely(),
            $this->subscriptionSafely(),
        );

        return $this->normaliseAll($reportRows, $extras);
    }

    /**
     * @param  array<string, array<string, mixed>>  ...$maps
     * @return array<string, array<string, mixed>>
     */
    private function mergeBySlug(array ...$maps): array
    {
        $merged = [];

        foreach ($maps as $map) {
            foreach ($map as $slug => $values) {
                $merged[$slug] = [...($merged[$slug] ?? []), ...$values];
            }
        }

        return $merged;
    }

    /**
     * Report rows turned into normalised rows, optionally enriched by the
     * current-window list. Split out from fetch() so the mapping can be
     * exercised without an HTTP round trip.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $currentWindow  Keyed by slug.
     * @return array<int, array<string, mixed>>
     */
    public function normaliseAll(array $rows, array $currentWindow = []): array
    {
        return array_values(array_filter(array_map(
            fn (array $row) => $this->normalise($row, $currentWindow),
            $rows,
        )));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalise(array $row, array $currentWindow = []): ?array
    {
        $company = $this->anchorText($row['Company'] ?? '');
        $slug = trim((string) ($row['~URLRewrite_Folder_Name'] ?? ''));

        if ($company === '') {
            return null;
        }

        $id = $this->anchorId($row['Company'] ?? '');
        $open = $this->date($row['~Issue_Open_Date'] ?? $row['Opening Date'] ?? null);
        $close = $this->date($row['~IssueCloseDate'] ?? $row['Closing Date'] ?? null);
        $listing = $this->date($row['~ListingDate'] ?? $row['Listing Date'] ?? null);

        [$priceMin, $priceMax] = $this->priceBand($row['Issue Price (Rs.)'] ?? null);

        // The report carries neither lot size, live status nor subscription —
        // those ride in from the other two endpoints, keyed by slug.
        $live = $slug !== '' ? ($currentWindow[$slug] ?? []) : [];
        $lotSize = $live['lot_size'] ?? null;

        $subscription = array_filter(
            $live,
            fn ($key) => str_starts_with($key, 'subscription_'),
            ARRAY_FILTER_USE_KEY,
        );

        return array_filter([
            ...$subscription,
            'external_id' => $this->key().':'.($id ?: 'slug-'.$slug ?: $this->slugify($company)),
            'external_source' => $this->key(),
            'external_slug' => $slug ?: null,
            'name' => $company,
            'issue_type' => $this->issueType($row['Issue Category'] ?? null, $row['Listing at'] ?? null),
            'open_date' => $open,
            'close_date' => $close,
            'listing_date' => $listing,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'price_per_share' => $priceMax,
            'lot_size' => $lotSize,
            'min_amount' => $lotSize && $priceMax ? $lotSize * $priceMax : null,
            'issue_size' => $this->issueSize($row),
            'logo_url' => $this->logo($row),
            'info_url' => $slug !== '' && $id
                ? $this->siteUrl.'/ipo/'.$slug.'/'.$id.'/'
                : null,
            'status' => $live['status'] ?? $this->statusFromDates($open, $close, $listing),
        ], fn ($value) => $value !== null && $value !== '' && $value !== 0);
    }

    /**
     * One category's report for the current financial year, following
     * pagination to the end.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws FeedException
     */
    private function report(string $category): array
    {
        $rows = [];
        $page = 1;

        do {
            $body = $this->getJson($this->reportUrl(self::REPORT_ID, $category, $page));

            foreach ($body['reportTableData'] ?? [] as $row) {
                $rows[] = $row;
            }

            $totalPages = (int) ($body['totalPages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= $this->maxPages);

        return $rows;
    }

    /**
     * How many times over each category has bid, keyed by slug.
     *
     * Only issues that are open or have just closed carry live numbers; the
     * rest come back blank and are simply left out.
     *
     * @return array<string, array<string, mixed>>
     */
    private function subscriptionSafely(): array
    {
        $subscriptions = [];

        foreach (self::CATEGORIES as $category) {
            try {
                $body = $this->getJson($this->reportUrl(self::SUBSCRIPTION_REPORT_ID, $category, 1));
            } catch (FeedException) {
                continue;
            }

            foreach ($body['reportTableData'] ?? [] as $row) {
                $slug = trim((string) ($row['~URLRewrite_Folder_Name'] ?? ''));

                if ($slug === '') {
                    continue;
                }

                $values = array_filter([
                    'subscription_retail' => $this->times($row['Retail (x)'] ?? null),
                    'subscription_total' => $this->times($row['Total (x)'] ?? null),
                    'subscription_qib' => $this->times($row['QIB (x)'] ?? null),
                    'subscription_nii' => $this->times($row['NII (x)'] ?? null),
                    'subscription_applications' => $this->applications($row['Applications'] ?? null),
                    'subscription_updated_at' => $this->timestamp($row['Subscription as on'] ?? null),
                ], fn ($value) => $value !== null);

                if ($values !== []) {
                    $subscriptions[$slug] = $values;
                }
            }
        }

        return $subscriptions;
    }

    /** "1.41" -> 1.41; blank or "0" before bidding opens -> null, not a real zero. */
    private function times(mixed $value): ?float
    {
        $value = trim((string) $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /** "1,07,874" — Indian digit grouping, so strip everything but the digits. */
    private function applications(mixed $value): ?int
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : (int) $digits;
    }

    /** "27-Aug-2026 11:53" and friends. */
    private function timestamp(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The IPOs open right now, keyed by slug — the only place lot size and an
     * explicit status are published.
     *
     * @return array<string, array<string, mixed>>
     */
    private function currentWindowSafely(): array
    {
        try {
            $body = $this->getJson($this->baseUrl.'/cloud/index/ipo-read/all');
        } catch (FeedException) {
            // Enrichment only. The report alone is still worth returning.
            return [];
        }

        $window = [];

        foreach ($body['ipoList'] ?? [] as $row) {
            $slug = trim((string) ($row['urlrewrite_folder_name'] ?? ''));

            if ($slug === '' || ! empty($row['issue_withdraw'])) {
                continue;
            }

            $window[$slug] = array_filter([
                'lot_size' => isset($row['market_lot_size']) ? (int) $row['market_lot_size'] : null,
                'status' => $this->mapListStatus($row['ipo_status'] ?? null),
            ]);
        }

        return $window;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws FeedException
     */
    private function getJson(string $url): array
    {
        try {
            $response = $this->request()->get($url);
        } catch (ConnectionException $exception) {
            throw new FeedException(
                'Could not reach chittorgarh.com — check the internet connection.',
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw new FeedException('chittorgarh.com returned HTTP '.$response->status().'.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new FeedException('chittorgarh.com returned an unexpected response.');
        }

        return $body;
    }

    /** Their reports all share one URL shape: id / page / month / year / FY / sort / category / 0. */
    private function reportUrl(int $reportId, string $category, int $page): string
    {
        $today = Carbon::today();

        return sprintf(
            '%s/cloud/report/data-read/%d/%d/%d/%d/%s/0/%s/0',
            $this->baseUrl,
            $reportId,
            $page,
            $today->month,
            $today->year,
            $this->financialYear($today),
            $category,
        );
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(30)
            ->retry(2, 1000, throw: false)
            // The endpoints serve a browser; a bare client is sometimes refused.
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; ipo-management/1.0)',
                'Referer' => $this->siteUrl.'/',
            ]);
    }

    /** Indian financial years run April to March: August 2026 is "2026-27". */
    private function financialYear(Carbon $date): string
    {
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return $start.'-'.substr((string) ($start + 1), -2);
    }

    /**
     * Company arrives as markup: an anchor holding the name, sometimes followed
     * by a status badge. Only the anchor's own text is the company name —
     * stripping tags wholesale would glue the badge letter onto the end.
     */
    private function anchorText(string $html): string
    {
        if (preg_match('/<a\b[^>]*>(.*?)<\/a>/is', $html, $match)) {
            return trim(html_entity_decode(strip_tags($match[1])));
        }

        return trim(html_entity_decode(strip_tags($html)));
    }

    /** The IPO's numeric id, taken out of the anchor's href. */
    private function anchorId(string $html): ?string
    {
        return preg_match('#/ipo/[^/"]+/(\d+)/#', $html, $match) ? $match[1] : null;
    }

    /** "168.00 to 177.00" -> [168.0, 177.0]; "59.00" -> [59.0, 59.0]. */
    private function priceBand(?string $price): array
    {
        if (blank($price)) {
            return [null, null];
        }

        preg_match_all('/\d+(?:\.\d+)?/', $price, $matches);

        $numbers = array_map('floatval', $matches[0] ?? []);

        return match (count($numbers)) {
            0 => [null, null],
            1 => [$numbers[0], $numbers[0]],
            default => [min($numbers), max($numbers)],
        };
    }

    private function issueSize(array $row): ?string
    {
        $amount = $row['Issue Amount (Rs.cr.)']
            ?? $row['Total Issue Amount (Incl.Firm reservations) (Rs.cr.)']
            ?? null;

        return is_numeric($amount) ? rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.').'cr' : null;
    }

    private function logo(array $row): ?string
    {
        $logo = trim((string) ($row['~compare_image'] ?? ''));

        return str_starts_with($logo, 'http') ? $logo : null;
    }

    private function issueType(?string $category, ?string $listingAt): ?string
    {
        $haystack = strtoupper(trim($category.' '.$listingAt));

        if ($haystack === '') {
            return null;
        }

        return str_contains($haystack, 'SME') ? 'SME' : 'EQ';
    }

    private function mapListStatus(?string $status): ?string
    {
        return match (strtolower(trim((string) $status))) {
            'upcoming' => 'upcoming',
            'current', 'open' => 'open',
            'closed' => 'closed',
            'listed' => 'listed',
            default => null,
        };
    }

    /**
     * The report publishes no status, but its dates say everything: an issue is
     * upcoming until it opens, open until it closes, and closed after that —
     * "listed" only once the listing date has actually passed.
     */
    private function statusFromDates(?string $open, ?string $close, ?string $listing): ?string
    {
        $today = Carbon::today()->toDateString();

        if ($listing && $listing <= $today) {
            return 'listed';
        }

        if ($close && $close < $today) {
            return 'closed';
        }

        if ($open && $open <= $today) {
            return 'open';
        }

        return $open ? 'upcoming' : null;
    }

    private function date(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function slugify(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');
    }
}
