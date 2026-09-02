<?php

namespace App\Services\IpoFeeds;

/**
 * One source of IPO listings.
 *
 * Every feed hands back rows in the same normalised shape — the `ipos` table's
 * own column names, plus `status` — so IpoSync can merge several sources without
 * knowing anything about where each one came from.
 *
 * Normalised keys: external_id, external_source, name, symbol, issue_type,
 * open_date, close_date, listing_date, allotment_date, price_min, price_max,
 * price_per_share, lot_size, min_amount, issue_size, logo_url, info_url,
 * prospectus_url, about, status. Unknown values are simply left out.
 */
interface IpoFeed
{
    /** Stable machine name, used to namespace external ids. */
    public function key(): string;

    /** How this feed is named to the user. */
    public function label(): string;

    /** False when the feed can't run — usually a missing API key. */
    public function isConfigured(): bool;

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws FeedException
     */
    public function fetch(): array;
}
