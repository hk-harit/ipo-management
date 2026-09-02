<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Applicant;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DedupeIposTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function ipo(array $attributes): Ipo
    {
        return Ipo::withoutGlobalScope('tenant')->create([
            'user_id' => $this->user->id,
            'status' => 'open',
            ...$attributes,
        ]);
    }

    private function applicationFor(Ipo $ipo): IpoApplication
    {
        $account = Account::withoutGlobalScope('tenant')->firstOrCreate(
            ['user_id' => $this->user->id, 'name' => 'Bank'],
            ['type' => 'bank'],
        );

        $applicant = Applicant::withoutGlobalScope('tenant')->firstOrCreate(
            ['user_id' => $this->user->id, 'name' => 'Friend'],
        );

        return IpoApplication::withoutGlobalScope('tenant')->create([
            'user_id' => $this->user->id,
            'ipo_id' => $ipo->id,
            'applicant_id' => $applicant->id,
            'account_id' => $account->id,
            'amount' => 15000,
            'applied_date' => now(),
            'status' => 'applied',
        ]);
    }

    public function test_it_reports_duplicates_without_touching_anything(): void
    {
        $this->ipo(['name' => 'Skyways Air Services Ltd.', 'external_slug' => 'skyways-air-ipo']);
        $this->ipo(['name' => 'Skyways Air', 'external_slug' => 'skyways-air-ipo']);

        $this->artisan('ipos:dedupe')
            ->expectsOutputToContain('duplicate group')
            ->expectsOutputToContain('Re-run with --force')
            ->assertSuccessful();

        $this->assertSame(2, Ipo::withoutGlobalScope('tenant')->count());
    }

    public function test_a_shared_slug_merges_two_rows(): void
    {
        $keeper = $this->ipo([
            'name' => 'Skyways Air Services Ltd.',
            'external_slug' => 'skyways-air-ipo',
            'lot_size' => 100,
        ]);
        $this->ipo(['name' => 'Skyways Air', 'external_slug' => 'skyways-air-ipo', 'gmp' => 46]);

        $this->artisan('ipos:dedupe --force')->assertSuccessful();

        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->count());

        $survivor = Ipo::withoutGlobalScope('tenant')->first();
        $this->assertSame($keeper->id, $survivor->id);
        $this->assertSame(100, $survivor->lot_size);
        // The merged row's premium is carried across rather than lost.
        $this->assertSame('46.00', $survivor->gmp);
    }

    /** Two rows the sync already recorded as one IPO, under unrelated names. */
    public function test_a_shared_feed_id_merges_rows_whose_names_look_nothing_alike(): void
    {
        $keeper = $this->ipo([
            'name' => "Purple Style Labs Ltd. (Pernia's Pop-up Studio)",
            'external_id' => 'chittorgarh:2622',
            'external_ids' => ['chittorgarh:2622', 'investorgain:1897'],
        ]);
        $this->ipo(['name' => "Pernia's Pop-up Studio", 'external_id' => 'investorgain:1897']);

        $this->artisan('ipos:dedupe --force')->assertSuccessful();

        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->count());
        $this->assertSame($keeper->id, Ipo::withoutGlobalScope('tenant')->first()->id);
    }

    public function test_ltd_and_limited_are_merged(): void
    {
        $this->ipo(['name' => 'Priority Jewels Limited']);
        $this->ipo(['name' => 'Priority Jewels Ltd.']);

        $this->artisan('ipos:dedupe --force')->assertSuccessful();

        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->count());
    }

    /** "Skyways Air" is the opening words of "Skyways Air Services". */
    public function test_a_shortened_name_is_merged_when_the_dates_agree(): void
    {
        $this->ipo(['name' => 'Skyways Air Services Ltd.', 'issue_type' => 'EQ', 'open_date' => '2026-08-24']);
        $this->ipo(['name' => 'Skyways Air', 'issue_type' => 'EQ', 'open_date' => '2026-08-24']);

        $this->artisan('ipos:dedupe --force')->assertSuccessful();

        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->count());
    }

    /** The prefix rule must not join two issues that merely start alike. */
    public function test_a_shortened_name_is_left_alone_when_the_dates_disagree(): void
    {
        $this->ipo(['name' => 'Skyways Air Services Ltd.', 'issue_type' => 'EQ', 'open_date' => '2026-08-24']);
        $this->ipo(['name' => 'Skyways Air', 'issue_type' => 'EQ', 'open_date' => '2024-01-10']);

        $this->artisan('ipos:dedupe --force')->assertSuccessful();

        $this->assertSame(2, Ipo::withoutGlobalScope('tenant')->count());
    }

    public function test_two_genuinely_different_companies_are_never_merged(): void
    {
        $this->ipo(['name' => 'Deepa Jewellers Ltd.', 'external_slug' => 'deepa-jewellers-ipo']);
        $this->ipo(['name' => 'Deepa Industries Ltd.', 'external_slug' => 'deepa-industries-ipo']);

        $this->artisan('ipos:dedupe --force')->assertSuccessful();

        $this->assertSame(2, Ipo::withoutGlobalScope('tenant')->count());
    }

    /**
     * The one outcome that must never happen: losing the row an application is
     * attached to. The row with applications is always the one kept.
     */
    public function test_the_row_carrying_applications_is_the_one_kept(): void
    {
        $bare = $this->ipo([
            'name' => 'Skyways Air Services Ltd.',
            'external_slug' => 'skyways-air-ipo',
            'lot_size' => 100,
            'gmp' => 46,
        ]);

        $applied = $this->ipo(['name' => 'Skyways Air', 'external_slug' => 'skyways-air-ipo']);
        $application = $this->applicationFor($applied);

        $this->artisan('ipos:dedupe --force')->assertSuccessful();

        $survivor = Ipo::withoutGlobalScope('tenant')->first();

        $this->assertSame($applied->id, $survivor->id);
        $this->assertSame($survivor->id, $application->fresh()->ipo_id);
        // The richer row is merged away, but its data moves across first.
        $this->assertSame(100, $survivor->lot_size);
        $this->assertSame('46.00', $survivor->gmp);
        $this->assertDatabaseMissing('ipos', ['id' => $bare->id]);
    }

    public function test_applications_on_both_rows_all_end_up_on_the_survivor(): void
    {
        $a = $this->ipo(['name' => 'Skyways Air Services Ltd.', 'external_slug' => 'skyways-air-ipo']);
        $b = $this->ipo(['name' => 'Skyways Air', 'external_slug' => 'skyways-air-ipo']);

        $this->applicationFor($a);
        $this->applicationFor($b);

        $this->artisan('ipos:dedupe --force')->assertSuccessful();

        $survivor = Ipo::withoutGlobalScope('tenant')->first();

        $this->assertSame(2, IpoApplication::withoutGlobalScope('tenant')->count());
        $this->assertSame(2, IpoApplication::withoutGlobalScope('tenant')->where('ipo_id', $survivor->id)->count());
    }

    public function test_one_users_duplicates_do_not_touch_another_users_rows(): void
    {
        $other = User::factory()->create();

        $this->ipo(['name' => 'Skyways Air Services Ltd.', 'external_slug' => 'skyways-air-ipo']);
        $this->ipo(['name' => 'Skyways Air', 'external_slug' => 'skyways-air-ipo']);

        Ipo::withoutGlobalScope('tenant')->create([
            'user_id' => $other->id,
            'name' => 'Skyways Air Services Ltd.',
            'external_slug' => 'skyways-air-ipo',
            'status' => 'open',
        ]);

        $this->artisan('ipos:dedupe --user='.$this->user->id.' --force')->assertSuccessful();

        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->where('user_id', $this->user->id)->count());
        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->where('user_id', $other->id)->count());
    }

    public function test_it_says_so_when_there_is_nothing_to_merge(): void
    {
        $this->ipo(['name' => 'Deepa Jewellers Ltd.', 'external_slug' => 'deepa-jewellers-ipo']);

        $this->artisan('ipos:dedupe')
            ->expectsOutputToContain('No duplicates found')
            ->assertSuccessful();
    }
}
