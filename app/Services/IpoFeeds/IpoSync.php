<?php

namespace App\Services\IpoFeeds;

use App\Models\Ipo;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Folds every configured IPO feed into one user's own `ipos` rows.
 *
 * Three rules shape everything here:
 *
 *  1. Feeds are merged in memory *before* anything is written, so an IPO listed
 *     by both sources lands as one row. Earlier feeds win on conflicts; later
 *     ones only fill in what is still missing.
 *
 *  2. The sync fills in the market's facts — name, dates, price band, lot size.
 *     It never touches what the user records about their own dealings, so
 *     `notes` and any application attached to the IPO are left alone.
 *
 *  3. Status only ever moves forward. Once the user has marked an IPO
 *     "allotted" or "listed", a feed still calling it "closed" must not drag it
 *     back — their bookkeeping is ahead of the feed, not wrong.
 */
class IpoSync
{
    /** Our statuses in order. A synced status is only applied if it is further along. */
    private const STATUS_ORDER = ['upcoming', 'open', 'closed', 'allotted', 'listed'];

    /** @var array<int, IpoFeed> */
    private array $feeds;

    /** @var array<string, string> Feed label => why it failed, for feeds that fell over. */
    private array $failures = [];

    public function __construct(IpoFeed ...$feeds)
    {
        $this->feeds = $feeds;
    }

    /**
     * Feeds in priority order. ipoalerts.in first for its symbols and
     * prospectus links; chittorgarh next for the breadth, the lot sizes and the
     * subscription figures; investorgain last, since GMP is all it adds.
     */
    public static function fromConfig(): self
    {
        return new self(
            IpoAlertsFeed::fromConfig(),
            ChittorgarhFeed::fromConfig(),
            InvestorGainGmpFeed::fromConfig(),
        );
    }

    /** @return array<string, string> */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Pull from every configured feed and reconcile the merged listing into
     * this user's IPOs.
     *
     * @throws FeedException when every feed failed
     */
    public function syncFor(User $user): IpoSyncResult
    {
        return $this->apply($user, $this->gather());
    }

    /**
     * Every feed's rows, merged into one listing keyed by normalised name.
     *
     * A feed that falls over is recorded and skipped rather than taking the
     * whole sync down with it — one dead source should not cost the user the
     * other. Only when nothing at all came back is it an error.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws FeedException
     */
    public function gather(): array
    {
        $this->failures = [];
        $merged = [];
        $bySlug = [];
        $byName = [];
        $ran = 0;

        foreach ($this->feeds as $feed) {
            if (! $feed->isConfigured()) {
                continue;
            }

            try {
                $rows = $feed->fetch();
                $ran++;
            } catch (FeedException $exception) {
                $this->failures[$feed->label()] = $exception->getMessage();

                continue;
            }

            foreach ($rows as $row) {
                $this->mergeRow($merged, $bySlug, $byName, $row);
            }
        }

        if ($ran === 0 && $this->failures !== []) {
            throw new FeedException(implode(' ', $this->failures));
        }

        return $merged;
    }

    /**
     * Folds one feed row into the merged listing.
     *
     * Two lookups, because neither alone is enough. The slug is the strong key —
     * chittorgarh and investorgain publish the same one, which is the only thing
     * connecting "Skyways Air" to "Skyways Air Services Ltd." — but ipoalerts
     * has no compatible slug, so its rows can only be matched by name.
     *
     * @param  array<string, array<string, mixed>>  $merged
     * @param  array<string, string>  $bySlug  slug => key into $merged
     * @param  array<string, string>  $byName  name key => key into $merged
     */
    private function mergeRow(array &$merged, array &$bySlug, array &$byName, array $row): void
    {
        $nameKey = $this->nameKey($row['name'] ?? '');
        $slug = $row['external_slug'] ?? null;

        if ($nameKey === '' && ! $slug) {
            return;
        }

        $key = ($slug !== null ? ($bySlug[$slug] ?? null) : null)
            ?? ($nameKey !== '' ? ($byName[$nameKey] ?? null) : null);

        if ($key === null) {
            $key = $slug ?? $nameKey;
            $row['external_ids'] = array_filter([$row['external_id'] ?? null]);
            $merged[$key] = $row;
        } else {
            $existing = $merged[$key];

            // The feed that got here first keeps its values; this one fills gaps.
            foreach ($row as $field => $value) {
                if (! array_key_exists($field, $existing) || $existing[$field] === null) {
                    $existing[$field] = $value;
                }
            }

            // Status is the exception: take whichever feed is further along.
            if (isset($row['status']) && $this->isAhead($row['status'], $existing['status'] ?? null)) {
                $existing['status'] = $row['status'];
            }

            // Remember every feed id for this IPO, not just the first claimed.
            // It is the only record that "Pernia's Pop-up Studio" and "Purple
            // Style Labs Ltd." are the same issue under two names.
            $existing['external_ids'] = array_values(array_unique(array_merge(
                $existing['external_ids'] ?? [],
                array_filter([$row['external_id'] ?? null]),
            )));

            $merged[$key] = $existing;
        }

        // Index every spelling seen for this IPO, so a later feed using either
        // the short name or the full one still lands on the same entry.
        if ($slug !== null) {
            $bySlug[$slug] = $key;
        }

        if ($nameKey !== '') {
            $byName[$nameKey] = $key;
        }

        $mergedName = $this->nameKey($merged[$key]['name'] ?? '');

        if ($mergedName !== '') {
            $byName[$mergedName] = $key;
        }
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows  Normalised feed rows.
     */
    public function apply(User $user, array $rows): IpoSyncResult
    {
        $result = new IpoSyncResult;

        // The user's whole list, indexed both ways up front. Matching by name
        // has to happen in PHP rather than SQL because the key is normalised
        // (see nameKey), and loading once beats a query per incoming row.
        $existing = Ipo::withoutGlobalScope('tenant')->where('user_id', $user->id)->get();
        $byExternalId = $existing->filter(fn (Ipo $ipo) => filled($ipo->external_id))->keyBy('external_id');
        $bySlug = $existing->filter(fn (Ipo $ipo) => filled($ipo->external_slug))->keyBy('external_slug');
        $byName = $existing->keyBy(fn (Ipo $ipo) => $this->nameKey($ipo->name));

        foreach ($rows as $row) {
            if (blank($row['external_id'] ?? null) || blank($row['name'] ?? null)) {
                $result->skipped++;

                continue;
            }

            $ipo = $byExternalId->get($row['external_id'])
                ?? ($row['external_slug'] ?? null ? $bySlug->get($row['external_slug']) : null)
                ?? $byName->get($this->nameKey($row['name']));

            if ($ipo === null) {
                $status = $row['status'] ?? 'upcoming';
                $implied = $this->statusFromDates(
                    $this->toDateString($row['open_date'] ?? null),
                    $this->toDateString($row['close_date'] ?? null),
                    $this->toDateString($row['allotment_date'] ?? null),
                    $this->toDateString($row['listing_date'] ?? null),
                );

                if ($implied !== null && $this->isAhead($implied, $status)) {
                    $status = $implied;
                }

                $ipo = Ipo::create([
                    'user_id' => $user->id,
                    ...$row,
                    'status' => $status,
                    'synced_at' => now(),
                ]);

                // Index it straight away, so a second row for the same IPO in
                // this very run updates it instead of creating a twin.
                $byExternalId->put($ipo->external_id, $ipo);
                $byName->put($this->nameKey($ipo->name), $ipo);

                if (filled($ipo->external_slug)) {
                    $bySlug->put($ipo->external_slug, $ipo);
                }

                $result->created++;

                continue;
            }

            // A row matched by name may be meeting its slug for the first time.
            if (filled($row['external_slug'] ?? null) && ! $bySlug->has($row['external_slug'])) {
                $bySlug->put($row['external_slug'], $ipo);
            }

            $changes = $this->changesFor($ipo, $row);

            if ($changes === []) {
                $ipo->forceFill(['synced_at' => now()])->saveQuietly();
                $result->unchanged++;

                continue;
            }

            $ipo->fill($changes)->save();
            $result->updated++;
        }

        return $result;
    }

    /**
     * Only the fields that actually differ, so an untouched IPO is not rewritten
     * on every run and `updated` counts mean something.
     *
     * @return array<string, mixed>
     */
    private function changesFor(Ipo $ipo, array $row): array
    {
        $changes = [];

        foreach ($row as $field => $value) {
            // Never blank out something we hold with a null from a feed, and
            // never hand one feed's id to a row the other feed already owns.
            if ($value === null || $field === 'status') {
                continue;
            }

            if ($field === 'external_id' && filled($ipo->external_id)) {
                continue;
            }

            // The id list only ever grows — an id seen on an earlier run still
            // identifies this IPO even if a feed has since stopped listing it.
            if ($field === 'external_ids') {
                $known = array_values(array_unique(array_merge(
                    $ipo->external_ids ?? [],
                    array_filter([$ipo->external_id]),
                    $value,
                )));

                sort($known);

                if ($known !== $this->sorted($ipo->external_ids)) {
                    $changes['external_ids'] = $known;
                }

                continue;
            }

            if ($this->differs($ipo->getAttribute($field), $value)) {
                $changes[$field] = $value;
            }
        }

        if (isset($row['status']) && $this->isAhead($row['status'], $ipo->status)) {
            $changes['status'] = $row['status'];
        }

        // No feed ever says "allotted" outright (ipoalerts.in has no such
        // status value, chittorgarh's live list skips straight from "closed"
        // to "listed"), and a feed's own per-run snapshot can leave a date
        // blank the moment an IPO drops off its "current" list — without that
        // date having stopped being true. So this looks at the union of every
        // date already on file plus whatever's changing this run, not just
        // this run's row in isolation, and lets isAhead() below keep it a
        // forward-only nudge on top of whatever the feed itself said.
        $implied = $this->statusFromDates(
            $this->toDateString($changes['open_date'] ?? $ipo->open_date),
            $this->toDateString($changes['close_date'] ?? $ipo->close_date),
            $this->toDateString($changes['allotment_date'] ?? $ipo->allotment_date),
            $this->toDateString($changes['listing_date'] ?? $ipo->listing_date),
        );

        if ($implied !== null && $this->isAhead($implied, $changes['status'] ?? $ipo->status)) {
            $changes['status'] = $implied;
        }

        if ($changes !== []) {
            $changes['synced_at'] = now();
        }

        return $changes;
    }

    /**
     * The status a set of dates alone implies — open until it closes, closed
     * until allotment, allotted until it lists. Shared by both new-IPO
     * creation and updates to existing ones so "when is this actually
     * allotted/listed" is answered the same way everywhere, regardless of
     * which feed (if any) happened to say so explicitly.
     */
    private function statusFromDates(?string $open, ?string $close, ?string $allotment, ?string $listing): ?string
    {
        $today = Carbon::today()->toDateString();

        return match (true) {
            $listing !== null && $listing <= $today => 'listed',
            $allotment !== null && $allotment <= $today => 'allotted',
            $close !== null && $close < $today => 'closed',
            $open !== null && $open <= $today => 'open',
            default => null,
        };
    }

    /** A date, whichever shape it arrives in (Carbon from the model, string from a feed row), as "Y-m-d" — or null if it's neither. */
    private function toDateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int, mixed> */
    private function sorted(?array $values): array
    {
        $values = array_values($values ?? []);
        sort($values);

        return $values;
    }

    private function differs(mixed $current, mixed $incoming): bool
    {
        if ($current === null) {
            return true;
        }

        if ($current instanceof Carbon) {
            return ! $current->isSameDay(Carbon::parse($incoming));
        }

        if (is_numeric($current) && is_numeric($incoming)) {
            return abs((float) $current - (float) $incoming) > 0.001;
        }

        return (string) $current !== (string) $incoming;
    }

    /**
     * The key two feeds have to agree on for the same IPO to land as one row.
     *
     * They rarely spell a company identically: ipoalerts.in says "Priority
     * Jewels Limited" where chittorgarh.com says "Priority Jewels Ltd.", and one
     * of them tacks a trading alias on in brackets. So the key drops the
     * bracketed alias, flattens punctuation, and strips the legal suffix —
     * leaving "priority jewels" either way.
     */
    private function nameKey(string $name): string
    {
        $key = mb_strtolower(trim($name));

        // "Purple Style Labs Ltd. (Pernia's Pop-up Studio)" -> drop the alias.
        $key = preg_replace('/\s*\([^)]*\)/u', '', $key);

        // Punctuation to spaces, so "ltd." and "ltd" become the same word.
        $key = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key);

        $key = preg_replace('/\b(private|pvt|limited|ltd|inc|corporation|corp)\b/u', ' ', $key);

        return trim(preg_replace('/\s+/u', ' ', $key));
    }

    /** True when the incoming status is genuinely further along than what we hold. */
    private function isAhead(string $incoming, ?string $current): bool
    {
        if ($current === null) {
            return true;
        }

        return array_search($incoming, self::STATUS_ORDER, true)
            > array_search($current, self::STATUS_ORDER, true);
    }
}
