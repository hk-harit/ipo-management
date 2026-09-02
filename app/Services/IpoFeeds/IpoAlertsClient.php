<?php

namespace App\Services\IpoFeeds;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A thin read-only client for ipoalerts.in.
 *
 * The API is paginated and gated by an `x-api-key` header. Without a key it
 * still answers, but caps every page at a single IPO and allows only three
 * requests a minute — enough to prove the wiring works, not enough to be
 * useful, so callers are told plainly when the key is missing.
 */
class IpoAlertsClient
{
    /** The statuses worth pulling. "announced" IPOs have no dates yet but are worth seeing early. */
    public const STATUSES = ['open', 'upcoming', 'announced', 'closed'];

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $baseUrl = 'https://api.ipoalerts.in',
        private readonly int $maxPages = 20,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiKey: config('services.ipoalerts.key'),
            baseUrl: rtrim((string) config('services.ipoalerts.base_url'), '/'),
            maxPages: (int) config('services.ipoalerts.max_pages', 20),
        );
    }

    public function hasApiKey(): bool
    {
        return filled($this->apiKey);
    }

    /**
     * Every IPO the API holds for one status, following pagination to the end.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws FeedException
     */
    public function fetch(string $status): array
    {
        $ipos = [];
        $page = 1;

        do {
            $body = $this->get($status, $page);

            foreach ($body['ipos'] ?? [] as $ipo) {
                $ipos[] = $ipo;
            }

            $totalPages = (int) ($body['meta']['totalPages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= $this->maxPages);

        return $ipos;
    }

    /**
     * Every IPO across every status, keyed by the API's own id so the same IPO
     * appearing under two statuses is only carried once.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws FeedException
     */
    public function fetchAll(): array
    {
        $ipos = [];

        foreach (self::STATUSES as $status) {
            foreach ($this->fetch($status) as $ipo) {
                $key = (string) ($ipo['id'] ?? $ipo['slug'] ?? '');

                if ($key !== '') {
                    $ipos[$key] = $ipo;
                }
            }
        }

        return $ipos;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws FeedException
     */
    private function get(string $status, int $page): array
    {
        $request = Http::acceptJson()
            ->timeout(30)
            ->retry(2, 1000, throw: false);

        if ($this->hasApiKey()) {
            $request = $request->withHeaders(['x-api-key' => $this->apiKey]);
        }

        try {
            $response = $request->get($this->baseUrl.'/ipos', [
                'status' => $status,
                'page' => $page,
            ]);
        } catch (ConnectionException $exception) {
            throw new FeedException(
                'Could not reach ipoalerts.in — check the internet connection.',
                previous: $exception,
            );
        }

        $body = $response->json() ?? [];

        if ($response->status() === 429) {
            throw new FeedException(
                'ipoalerts.in is rate limiting us'.
                ($this->hasApiKey() ? '.' : ' — without an API key only 3 requests a minute are allowed.')
            );
        }

        if (isset($body['error'])) {
            throw new FeedException((string) $body['error']);
        }

        if ($response->failed()) {
            throw new FeedException(
                'ipoalerts.in returned HTTP '.$response->status().
                ($body['detail'] ?? null ? ': '.$body['detail'] : '')
            );
        }

        return $body;
    }
}
