<?php

namespace App\Services\IpoFeeds;

use Illuminate\Support\Carbon;

/**
 * ipoalerts.in — the detailed feed.
 *
 * Gives real dates with years, a price band, the allotment date, the prospectus
 * and a company description, but needs an API key: without one it caps every
 * page at a single IPO.
 */
class IpoAlertsFeed implements IpoFeed
{
    public function __construct(private readonly IpoAlertsClient $client) {}

    public static function fromConfig(): self
    {
        return new self(IpoAlertsClient::fromConfig());
    }

    public function key(): string
    {
        return 'ipoalerts';
    }

    public function label(): string
    {
        return 'ipoalerts.in';
    }

    /**
     * Runs without a key too, just crippled — worth attempting so a fresh
     * install shows something, with the caller warning about the cap.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    public function hasApiKey(): bool
    {
        return $this->client->hasApiKey();
    }

    public function fetch(): array
    {
        return $this->normaliseAll($this->client->fetchAll());
    }

    /**
     * Raw API payloads turned into normalised rows, with anything unusable
     * dropped. Split out from fetch() so the mapping can be exercised without
     * standing up an HTTP round trip.
     *
     * @param  array<int|string, array<string, mixed>>  $payloads
     * @return array<int, array<string, mixed>>
     */
    public function normaliseAll(array $payloads): array
    {
        return array_values(array_filter(array_map(
            fn (array $payload) => $this->normalise($payload),
            $payloads,
        )));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalise(array $payload): ?array
    {
        $externalId = (string) ($payload['id'] ?? '');
        $name = trim((string) ($payload['name'] ?? ''));

        if ($externalId === '' || $name === '') {
            return null;
        }

        [$priceMin, $priceMax] = $this->priceBand($payload['priceRange'] ?? null);

        return array_filter([
            'external_id' => $this->key().':'.$externalId,
            'external_source' => $payload['source'] ?? $this->key(),
            'name' => $name,
            'symbol' => $payload['symbol'] ?? null,
            'issue_type' => $payload['type'] ?? null,
            'open_date' => $this->date($payload['startDate'] ?? null),
            'close_date' => $this->date($payload['endDate'] ?? null),
            'listing_date' => $this->date($payload['listingDate'] ?? null),
            'allotment_date' => $this->scheduleDate($payload, 'Allotment finalization'),
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            // The top of the band is what an application is priced at, and what
            // the rest of the app has always meant by price_per_share.
            'price_per_share' => $priceMax,
            'lot_size' => isset($payload['minQty']) ? (int) $payload['minQty'] : null,
            'min_amount' => isset($payload['minAmount']) ? (float) $payload['minAmount'] : null,
            'issue_size' => $payload['issueSize'] ?? null,
            'logo_url' => $payload['logo'] ?? null,
            'info_url' => $payload['infoUrl'] ?? null,
            'prospectus_url' => $payload['prospectusUrl'] ?? null,
            'about' => $payload['about'] ?? null,
            'status' => $this->status($payload['status'] ?? null),
        ], fn ($value) => $value !== null && $value !== '' && $value !== '–');
    }

    /** "190-200" -> [190.0, 200.0]; "10-10" -> [10.0, 10.0]; anything odd -> [null, null]. */
    private function priceBand(?string $range): array
    {
        if (blank($range)) {
            return [null, null];
        }

        $parts = array_values(array_filter(
            array_map('trim', explode('-', $range)),
            fn ($part) => is_numeric($part),
        ));

        return match (count($parts)) {
            0 => [null, null],
            1 => [(float) $parts[0], (float) $parts[0]],
            default => [(float) $parts[0], (float) $parts[1]],
        };
    }

    private function date(?string $date): ?string
    {
        if (blank($date)) {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Dates the feed only exposes inside its `schedule` array. */
    private function scheduleDate(array $payload, string $event): ?string
    {
        foreach ($payload['schedule'] ?? [] as $entry) {
            if (($entry['event'] ?? null) === $event) {
                return $this->date($entry['date'] ?? null);
            }
        }

        return null;
    }

    private function status(?string $status): ?string
    {
        return match ($status) {
            'announced', 'upcoming' => 'upcoming',
            'open' => 'open',
            'closed' => 'closed',
            'listed' => 'listed',
            default => null,
        };
    }
}
