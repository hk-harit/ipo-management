<?php

namespace App\Services\IpoFeeds;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * investorgain.com — the grey market premium feed. No API key.
 *
 * GMP is what an unlisted share is quoted at above its issue price before it
 * lists: the market's own guess at the listing gain, and the number that decides
 * whether an IPO is worth applying for at all. It is an unofficial, unregulated
 * quote — treat it as a signal, never as a promise.
 *
 * This feed runs last, so it only ever adds GMP to IPOs the other feeds have
 * already described. Like them it is an internal endpoint, not a published API.
 */
class InvestorGainGmpFeed implements IpoFeed
{
    /** Their "live IPO GMP" report. */
    private const REPORT_ID = 331;

    /** Their own name for the two boards: "ipo" is mainboard. */
    private const CATEGORIES = ['ipo', 'sme'];

    public function __construct(
        private readonly bool $enabled = true,
        private readonly string $baseUrl = 'https://webnodejs.investorgain.com',
        private readonly string $siteUrl = 'https://www.investorgain.com',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            enabled: (bool) config('services.investorgain.enabled', true),
            baseUrl: rtrim((string) config('services.investorgain.base_url'), '/'),
            siteUrl: rtrim((string) config('services.investorgain.site_url'), '/'),
        );
    }

    public function key(): string
    {
        return 'investorgain';
    }

    public function label(): string
    {
        return 'investorgain.com (GMP)';
    }

    public function isConfigured(): bool
    {
        return $this->enabled;
    }

    public function fetch(): array
    {
        $rows = [];

        foreach (self::CATEGORIES as $category) {
            foreach ($this->report($category) as $row) {
                $rows[] = $row;
            }
        }

        return $this->normaliseAll($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function normaliseAll(array $rows): array
    {
        return array_values(array_filter(array_map(
            fn (array $row) => $this->normalise($row),
            $rows,
        )));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalise(array $row): ?array
    {
        // "~ipo_name" is the clean company name; the "Name" cell has a status
        // letter welded onto the end ("Rays of Belief IPOU").
        $name = trim((string) ($row['~ipo_name'] ?? ''));
        $id = trim((string) ($row['~id'] ?? ''));

        if ($name === '' || $id === '') {
            return null;
        }

        [$gmp, $gmpPercent] = $this->gmp($row);

        return array_filter([
            'external_id' => $this->key().':'.$id,
            'external_source' => $this->key(),
            // The one field that ties this feed's short names ("Skyways Air") to
            // chittorgarh's full ones ("Skyways Air Services Ltd.").
            'external_slug' => $this->slug($row['~urlrewrite_folder_name'] ?? null),
            'name' => $name,
            'issue_type' => $this->issueType($row['~IPO_Category'] ?? $row['~ipo_category1'] ?? null),
            'open_date' => $this->date($row['~Srt_Open'] ?? null),
            'close_date' => $this->date($row['~Srt_Close'] ?? null),
            'allotment_date' => $this->date($row['~Srt_BoA_Dt'] ?? null),
            'listing_date' => $this->date($row['~Str_Listing'] ?? null),
            'price_per_share' => $this->number($row['Price (₹)'] ?? null),
            'price_max' => $this->number($row['Price (₹)'] ?? null),
            'lot_size' => ($lot = $this->number($row['Lot'] ?? null)) ? (int) $lot : null,
            'gmp' => $gmp,
            'gmp_percent' => $gmpPercent,
            'gmp_updated_at' => $gmp === null ? null : $this->updatedAt($row['Updated-On'] ?? null),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * GMP arrives as one HTML-ish cell — "&#8377;15 (6.28%)" — with "₹-- (%)"
     * standing for "no quote yet". The percentage is also published clean, so
     * that is preferred and the cell is only mined for the rupee figure.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function gmp(array $row): array
    {
        $cell = html_entity_decode(strip_tags((string) ($row['GMP'] ?? '')), ENT_QUOTES | ENT_HTML5);

        // Strip the bracketed percentage first so it can't be read as the amount.
        $amountPart = preg_replace('/\(.*?\)/', '', $cell);

        $gmp = preg_match('/-?\d+(?:\.\d+)?/', $amountPart, $match) ? (float) $match[0] : null;

        $percent = $this->number($row['~gmp_percent_calc'] ?? null);

        if ($percent === null && $gmp !== null && preg_match('/\(\s*(-?\d+(?:\.\d+)?)\s*%/', $cell, $match)) {
            $percent = (float) $match[1];
        }

        return [$gmp, $percent];
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws FeedException
     */
    private function report(string $category): array
    {
        $today = Carbon::today();

        // Note the "v2" path and one segment fewer than chittorgarh's reports.
        $url = sprintf(
            '%s/cloud/v2/report/data-read/%d/1/%d/%d/%s/0/%s',
            $this->baseUrl,
            self::REPORT_ID,
            $today->month,
            $today->year,
            $this->financialYear($today),
            $category,
        );

        try {
            $response = Http::acceptJson()
                ->timeout(30)
                ->retry(2, 1000, throw: false)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; ipo-management/1.0)',
                    'Referer' => $this->siteUrl.'/',
                ])
                ->get($url, ['search' => '']);
        } catch (ConnectionException $exception) {
            throw new FeedException(
                'Could not reach investorgain.com — check the internet connection.',
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw new FeedException('investorgain.com returned HTTP '.$response->status().'.');
        }

        $body = $response->json();

        if (! is_array($body) || ! isset($body['reportTableData'])) {
            throw new FeedException('investorgain.com returned an unexpected response.');
        }

        return is_array($body['reportTableData']) ? $body['reportTableData'] : [];
    }

    private function financialYear(Carbon $date): string
    {
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return $start.'-'.substr((string) ($start + 1), -2);
    }

    /** "/gmp/skyways-air-ipo/1820/" -> "skyways-air-ipo". */
    private function slug(?string $path): ?string
    {
        $parts = array_values(array_filter(explode('/', (string) $path)));

        // The path is /gmp/{slug}/{id}/ — the slug is the one non-numeric part
        // after the "gmp" segment.
        foreach ($parts as $part) {
            if ($part !== 'gmp' && ! is_numeric($part)) {
                return $part;
            }
        }

        return null;
    }

    private function issueType(?string $category): ?string
    {
        $category = strtoupper(trim((string) $category));

        return match (true) {
            $category === '' => null,
            str_contains($category, 'SME') => 'SME',
            default => 'EQ',
        };
    }

    private function number(mixed $value): ?float
    {
        $value = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5));

        return preg_match('/-?\d+(?:\.\d+)?/', $value, $match) ? (float) $match[0] : null;
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

    /** "27-Aug 11:35" — day, month and time, but no year. */
    private function updatedAt(?string $value): string
    {
        $value = trim((string) $value);

        if ($value !== '') {
            try {
                $parsed = Carbon::parse($value.' '.Carbon::today()->year);

                // A quote stamped in the future means it belongs to last year.
                if ($parsed->isAfter(Carbon::now()->addDay())) {
                    $parsed->subYear();
                }

                return $parsed->toDateTimeString();
            } catch (\Throwable) {
                // Fall through to "now" — the quote was live when we fetched it.
            }
        }

        return Carbon::now()->toDateTimeString();
    }
}
