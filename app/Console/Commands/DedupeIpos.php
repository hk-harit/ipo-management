<?php

namespace App\Console\Commands;

use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Merges IPOs that are really the same issue recorded twice.
 *
 * The feeds spell companies differently — "Skyways Air" against "Skyways Air
 * Services Ltd." — so before the slug became the matching key, one issue could
 * be written as two rows. This cleans up what that left behind.
 *
 * Nothing is deleted without --force. The default run only reports.
 */
class DedupeIpos extends Command
{
    protected $signature = 'ipos:dedupe
                            {--user= : Only clean up this user id (default: every user)}
                            {--force : Actually merge. Without this the command only reports.}';

    protected $description = 'Find IPOs recorded twice under different names and merge them into one';

    public function handle(): int
    {
        $users = $this->option('user')
            ? User::where('id', $this->option('user'))->get()
            : User::orderBy('id')->get();

        $groupsFound = 0;

        foreach ($users as $user) {
            $groups = $this->duplicateGroups($user);

            if ($groups->isEmpty()) {
                $this->line(sprintf('  %-24s nothing to merge.', $user->name));

                continue;
            }

            $this->newLine();
            $this->info($user->name.' — '.$groups->count().' duplicate group(s):');

            foreach ($groups as $group) {
                $groupsFound++;
                $this->reportGroup($group);

                if ($this->option('force')) {
                    $this->merge($group);
                }
            }
        }

        $this->newLine();

        if ($groupsFound === 0) {
            $this->info('No duplicates found.');

            return self::SUCCESS;
        }

        if ($this->option('force')) {
            $this->info('Merged '.$groupsFound.' group(s).');
        } else {
            $this->warn('Nothing was changed. Re-run with --force to merge.');
        }

        return self::SUCCESS;
    }

    /**
     * IPOs of one user that are really the same issue.
     *
     * Three signals link two rows, and they have to be applied together rather
     * than as competing group keys — a row written before slugs existed has
     * only its name, while its twin has only a slug:
     *
     *   - the same slug;
     *   - the same normalised name;
     *   - one normalised name being the leading words of the other
     *     ("skyways air" inside "skyways air services"), which is exactly how
     *     investorgain's short names differ from chittorgarh's full ones.
     *
     * The prefix rule is the loose one, so it also demands that the two agree
     * on board and opening date wherever both know them.
     *
     * @return \Illuminate\Support\Collection<int, Collection<int, Ipo>>
     */
    private function duplicateGroups(User $user): \Illuminate\Support\Collection
    {
        $ipos = Ipo::withoutGlobalScope('tenant')
            ->where('user_id', $user->id)
            ->withCount('applications')
            ->get();

        $parent = [];
        $find = function (int $id) use (&$parent, &$find): int {
            return $parent[$id] === $id ? $id : $parent[$id] = $find($parent[$id]);
        };
        $union = function (int $a, int $b) use (&$parent, $find): void {
            $parent[$find($a)] = $find($b);
        };

        foreach ($ipos as $ipo) {
            $parent[$ipo->id] = $ipo->id;
        }

        $bySlug = [];
        $byName = [];
        $byExternalId = [];

        foreach ($ipos as $ipo) {
            // The strongest signal of all: the sync recorded these ids as one
            // IPO, so a stray row carrying any of them is that same IPO.
            foreach (array_unique(array_filter([$ipo->external_id, ...($ipo->external_ids ?? [])])) as $externalId) {
                if (isset($byExternalId[$externalId])) {
                    $union($ipo->id, $byExternalId[$externalId]);
                }

                $byExternalId[$externalId] = $ipo->id;
            }

            if (filled($ipo->external_slug)) {
                if (isset($bySlug[$ipo->external_slug])) {
                    $union($ipo->id, $bySlug[$ipo->external_slug]);
                }

                $bySlug[$ipo->external_slug] = $ipo->id;
            }

            $name = $this->nameKey($ipo->name);

            if ($name === '') {
                continue;
            }

            if (isset($byName[$name])) {
                $union($ipo->id, $byName[$name]);
            }

            $byName[$name] = $ipo->id;
        }

        foreach ($ipos as $a) {
            foreach ($ipos as $b) {
                if ($a->id >= $b->id) {
                    continue;
                }

                if ($this->isShortenedNameOf($a, $b)) {
                    $union($a->id, $b->id);
                }
            }
        }

        return $ipos
            ->groupBy(fn (Ipo $ipo) => $find($ipo->id))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->values();
    }

    /** One name being the opening words of the other, on an IPO that otherwise agrees. */
    private function isShortenedNameOf(Ipo $a, Ipo $b): bool
    {
        $shortName = $this->nameKey($a->name);
        $longName = $this->nameKey($b->name);

        if (strlen($shortName) > strlen($longName)) {
            [$shortName, $longName] = [$longName, $shortName];
        }

        if ($shortName === '' || $shortName === $longName) {
            return false;
        }

        // At least two words, so a single common word can't drag rows together.
        if (str_word_count($shortName) < 2 || ! str_starts_with($longName, $shortName.' ')) {
            return false;
        }

        return $this->agreesOn($a, $b, 'issue_type') && $this->agreesOn($a, $b, 'open_date');
    }

    /** True when both hold the same value, or at least one doesn't know it. */
    private function agreesOn(Ipo $a, Ipo $b, string $field): bool
    {
        $left = $a->getAttribute($field);
        $right = $b->getAttribute($field);

        if (blank($left) || blank($right)) {
            return true;
        }

        return (string) $left === (string) $right;
    }

    /** @param  Collection<int, Ipo>  $group */
    private function reportGroup(Collection $group): void
    {
        $keeper = $this->keeper($group);

        foreach ($group as $ipo) {
            $this->line(sprintf(
                '   %s #%-5d %-42s %s',
                $ipo->is($keeper) ? 'keep  ' : 'merge ',
                $ipo->id,
                mb_substr($ipo->name, 0, 42),
                $ipo->applications_count.' application(s)',
            ));
        }
    }

    /**
     * The row to keep: whichever carries applications, then whichever is most
     * complete, then the oldest. Losing a row the user has already applied
     * through would be the one unforgivable outcome here.
     *
     * @param  Collection<int, Ipo>  $group
     */
    private function keeper(Collection $group): Ipo
    {
        return $group->sortByDesc(fn (Ipo $ipo) => [
            $ipo->applications_count,
            $this->completeness($ipo),
            -$ipo->id,
        ])->first();
    }

    private function completeness(Ipo $ipo): int
    {
        $fields = [
            'external_slug', 'symbol', 'open_date', 'close_date', 'listing_date',
            'price_min', 'price_max', 'lot_size', 'issue_size', 'gmp',
            'subscription_retail', 'prospectus_url', 'about',
        ];

        return count(array_filter($fields, fn (string $field) => filled($ipo->getAttribute($field))));
    }

    /**
     * Moves every application onto the keeper, fills its blanks from the rows
     * being merged away, and deletes them.
     *
     * @param  Collection<int, Ipo>  $group
     */
    private function merge(Collection $group): void
    {
        $keeper = $this->keeper($group);
        $losers = $group->reject(fn (Ipo $ipo) => $ipo->is($keeper));

        DB::transaction(function () use ($keeper, $losers) {
            foreach ($losers as $loser) {
                IpoApplication::withoutGlobalScope('tenant')
                    ->where('ipo_id', $loser->id)
                    ->update(['ipo_id' => $keeper->id]);

                foreach ($loser->getAttributes() as $field => $value) {
                    if (in_array($field, ['id', 'user_id', 'created_at', 'updated_at'], true)) {
                        continue;
                    }

                    if (blank($keeper->getAttribute($field)) && filled($value)) {
                        $keeper->setAttribute($field, $value);
                    }
                }

                $loser->delete();
            }

            $keeper->save();
        });
    }

    /** Mirrors IpoSync's own name normalisation. */
    private function nameKey(string $name): string
    {
        $key = mb_strtolower(trim($name));
        $key = preg_replace('/\s*\([^)]*\)/u', '', $key);
        $key = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key);
        $key = preg_replace('/\b(private|pvt|limited|ltd|inc|corporation|corp)\b/u', ' ', $key);

        return trim(preg_replace('/\s+/u', ' ', $key));
    }
}
