<?php

namespace Tests\Feature;

use App\Filament\Resources\IpoResource\Pages\ListIpos;
use App\Models\Ipo;
use App\Models\User;
use App\Services\IpoFeeds\ChittorgarhFeed;
use App\Services\IpoFeeds\InvestorGainGmpFeed;
use App\Services\IpoFeeds\IpoSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class IpoGmpAndSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Carbon::setTestNow('2026-08-27');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** One row as investorgain's GMP report returns it. */
    private function gmpRow(array $overrides = []): array
    {
        return array_replace([
            '~id' => '1820',
            '~ipo_name' => 'Skyways Air',
            '~IPO_Category' => 'IPO',
            'Name' => 'Skyways Air IPOO',
            'GMP' => '&#8377;46 (33.33%)',
            '~gmp_percent_calc' => '33.33',
            '~max_gmp1' => '50',
            'Price (₹)' => '138',
            'Lot' => '100',
            'IPO Size' => '&#8377;582.80 Cr',
            'Updated-On' => '27-Aug 11:35',
            '~Srt_Open' => '2026-08-24',
            '~Srt_Close' => '2026-08-27',
            '~Srt_BoA_Dt' => '2026-08-28',
            '~Str_Listing' => '2026-09-01',
            '~urlrewrite_folder_name' => '/gmp/skyways-air-ipo/1820/',
        ], $overrides);
    }

    /** One row as chittorgarh's financial-year report returns it. */
    private function reportRow(array $overrides = []): array
    {
        return array_replace([
            'Company' => '<a href="https://www.chittorgarh.com/ipo/skyways-air-ipo/2801/">Skyways Air Services Ltd.</a>',
            'Issue Category' => 'Mainboard',
            'Issue Price (Rs.)' => '138.00',
            'Issue Amount (Rs.cr.)' => '582.80',
            '~URLRewrite_Folder_Name' => 'skyways-air-ipo',
            '~Issue_Open_Date' => '2026-08-24',
            '~IssueCloseDate' => '2026-08-27',
            '~ListingDate' => '',
            'Listing at' => 'BSE, NSE',
        ], $overrides);
    }

    /** One row as chittorgarh's subscription report returns it. */
    private function subscriptionRow(array $overrides = []): array
    {
        return array_replace([
            'Company' => '<a href="https://www.chittorgarh.com/ipo/skyways-air-ipo/2801/">Skyways Air Services Ltd.</a>',
            '~URLRewrite_Folder_Name' => 'skyways-air-ipo',
            'QIB (x)' => '18.55',
            'NII (x)' => '9.20',
            'Retail (x)' => '12.88',
            'Total (x)' => '13.40',
            'Applications' => '1,07,874',
            'Subscription as on' => '27-Aug-2026 11:53',
        ], $overrides);
    }

    private function fakeFeeds(array $report = [], array $subscription = [], array $gmp = [], array $window = []): void
    {
        Http::fake([
            'api.ipoalerts.in/*' => Http::response(['meta' => ['totalPages' => 1], 'ipos' => []]),
            'webnodejs.chittorgarh.com/cloud/report/data-read/21/*' => Http::response([
                'msg' => 1, 'totalPages' => 1, 'reportTableData' => $subscription,
            ]),
            'webnodejs.chittorgarh.com/cloud/report/*' => Http::response([
                'msg' => 1, 'totalPages' => 1, 'reportTableData' => $report,
            ]),
            'webnodejs.chittorgarh.com/cloud/index/*' => Http::response(['msg' => 1, 'ipoList' => $window]),
            'webnodejs.investorgain.com/*' => Http::response([
                'msg' => 1, 'totalRecords' => count($gmp), 'reportTableData' => $gmp,
            ]),
        ]);
    }

    // ---- GMP feed --------------------------------------------------------

    public function test_it_reads_the_premium_and_the_percentage(): void
    {
        $ipo = (new InvestorGainGmpFeed)->normaliseAll([$this->gmpRow()])[0];

        $this->assertSame('Skyways Air', $ipo['name']);
        $this->assertSame(46.0, $ipo['gmp']);
        $this->assertSame(33.33, $ipo['gmp_percent']);
        $this->assertSame('investorgain:1820', $ipo['external_id']);
        $this->assertSame('skyways-air-ipo', $ipo['external_slug']);
        $this->assertSame(100, $ipo['lot_size']);
        $this->assertSame(138.0, $ipo['price_per_share']);
    }

    /** "₹-- (%)" is the feed's way of saying there is no quote yet. */
    public function test_an_ipo_with_no_quote_yet_carries_no_premium(): void
    {
        $ipo = (new InvestorGainGmpFeed)->normaliseAll([$this->gmpRow([
            'GMP' => '&#8377;-- (0.00%)',
            '~gmp_percent_calc' => '',
        ])])[0];

        $this->assertArrayNotHasKey('gmp', $ipo);
        $this->assertArrayNotHasKey('gmp_updated_at', $ipo);
    }

    public function test_a_negative_premium_is_kept_as_a_discount(): void
    {
        $ipo = (new InvestorGainGmpFeed)->normaliseAll([$this->gmpRow([
            'GMP' => '&#8377;-12 (-5.00%)',
            '~gmp_percent_calc' => '-5',
        ])])[0];

        $this->assertSame(-12.0, $ipo['gmp']);
        $this->assertSame(-5.0, $ipo['gmp_percent']);
    }

    /** The bracketed percentage must not be mistaken for the rupee figure. */
    public function test_the_percentage_in_brackets_is_not_read_as_the_premium(): void
    {
        $ipo = (new InvestorGainGmpFeed)->normaliseAll([$this->gmpRow([
            'GMP' => '&#8377;3.75 (3.79%)',
            '~gmp_percent_calc' => '3.79',
        ])])[0];

        $this->assertSame(3.75, $ipo['gmp']);
    }

    public function test_an_sme_gmp_row_is_marked_sme(): void
    {
        $ipo = (new InvestorGainGmpFeed)->normaliseAll([$this->gmpRow(['~IPO_Category' => 'SME'])])[0];

        $this->assertSame('SME', $ipo['issue_type']);
    }

    public function test_the_gmp_feed_can_be_switched_off(): void
    {
        $this->assertFalse((new InvestorGainGmpFeed(enabled: false))->isConfigured());
    }

    // ---- Subscription ----------------------------------------------------

    public function test_subscription_figures_reach_the_ipo(): void
    {
        $this->fakeFeeds([$this->reportRow()], [$this->subscriptionRow()]);

        (new IpoSync(new ChittorgarhFeed))->syncFor($this->user);

        $ipo = Ipo::withoutGlobalScope('tenant')->first();

        $this->assertSame('12.88', $ipo->subscription_retail);
        $this->assertSame('13.40', $ipo->subscription_total);
        $this->assertSame('18.55', $ipo->subscription_qib);
        // "1,07,874" — Indian digit grouping.
        $this->assertSame(107874, $ipo->subscription_applications);
        $this->assertSame('2026-08-27 11:53:00', $ipo->subscription_updated_at->toDateTimeString());
        $this->assertTrue($ipo->isRetailOversubscribed());
    }

    public function test_an_ipo_with_no_bidding_yet_carries_no_subscription(): void
    {
        $this->fakeFeeds([$this->reportRow()], [$this->subscriptionRow([
            'Retail (x)' => '', 'Total (x)' => '', 'QIB (x)' => '', 'NII (x)' => '', 'Applications' => '',
        ])]);

        (new IpoSync(new ChittorgarhFeed))->syncFor($this->user);

        $ipo = Ipo::withoutGlobalScope('tenant')->first();

        $this->assertNull($ipo->subscription_retail);
        $this->assertFalse($ipo->isRetailOversubscribed());
    }

    // ---- The name-matching problem GMP exposed ---------------------------

    /**
     * investorgain abbreviates — "Skyways Air" where chittorgarh says "Skyways
     * Air Services Ltd." — so name matching alone split one IPO into two rows.
     * The shared slug is what holds them together.
     */
    public function test_the_shared_slug_joins_a_short_name_to_a_full_one(): void
    {
        $this->fakeFeeds([$this->reportRow()], [$this->subscriptionRow()], [$this->gmpRow()]);

        $result = IpoSync::fromConfig()->syncFor($this->user);

        $this->assertSame(1, $result->created);
        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->count());

        $ipo = Ipo::withoutGlobalScope('tenant')->first();

        $this->assertSame('Skyways Air Services Ltd.', $ipo->name);
        $this->assertSame(46.0, (float) $ipo->gmp);
        $this->assertSame(12.88, (float) $ipo->subscription_retail);
    }

    /** Both feeds' ids are kept, so a stray row can be traced back later. */
    public function test_every_contributing_feed_id_is_recorded(): void
    {
        $this->fakeFeeds([$this->reportRow()], [], [$this->gmpRow()]);

        IpoSync::fromConfig()->syncFor($this->user);

        $ipo = Ipo::withoutGlobalScope('tenant')->first();

        $this->assertContains('chittorgarh:2801', $ipo->external_ids);
        $this->assertContains('investorgain:1820', $ipo->external_ids);
    }

    public function test_two_different_ipos_with_different_slugs_stay_apart(): void
    {
        $this->fakeFeeds(
            [$this->reportRow(), $this->reportRow([
                'Company' => '<a href="https://www.chittorgarh.com/ipo/deepa-jewellers-ipo/2827/">Deepa Jewellers Ltd.</a>',
                '~URLRewrite_Folder_Name' => 'deepa-jewellers-ipo',
            ])],
            [],
            [$this->gmpRow()],
        );

        $result = IpoSync::fromConfig()->syncFor($this->user);

        $this->assertSame(2, $result->created);
    }

    // ---- Derived numbers -------------------------------------------------

    public function test_the_estimated_gain_per_lot_is_the_premium_times_the_lot(): void
    {
        $ipo = Ipo::withoutGlobalScope('tenant')->create([
            'user_id' => $this->user->id,
            'name' => 'Test IPO',
            'status' => 'open',
            'price_per_share' => 138,
            'lot_size' => 100,
            'gmp' => 46,
        ]);

        $this->assertSame(4600.0, $ipo->estimated_gain_per_lot);
        $this->assertSame(184.0, $ipo->estimated_listing_price);
    }

    public function test_the_derived_numbers_are_null_without_a_premium(): void
    {
        $ipo = Ipo::withoutGlobalScope('tenant')->create([
            'user_id' => $this->user->id,
            'name' => 'Test IPO',
            'status' => 'open',
            'price_per_share' => 138,
            'lot_size' => 100,
        ]);

        $this->assertNull($ipo->estimated_gain_per_lot);
        $this->assertNull($ipo->estimated_listing_price);
    }

    // ---- The board toggle ------------------------------------------------

    private function makeIpo(string $name, string $type, string $status): Ipo
    {
        return Ipo::withoutGlobalScope('tenant')->create([
            'user_id' => $this->user->id,
            'name' => $name,
            'issue_type' => $type,
            'status' => $status,
        ]);
    }

    public function test_the_mainboard_tab_hides_sme_issues(): void
    {
        $this->actingAs($this->user);

        $main = $this->makeIpo('Big Board Ltd.', 'EQ', 'open');
        $sme = $this->makeIpo('Small Board Ltd.', 'SME', 'open');

        Livewire::test(ListIpos::class)
            ->set('activeTab', 'mainboard')
            ->assertCanSeeTableRecords([$main])
            ->assertCanNotSeeTableRecords([$sme]);
    }

    public function test_the_sme_tab_hides_mainboard_issues(): void
    {
        $this->actingAs($this->user);

        $main = $this->makeIpo('Big Board Ltd.', 'EQ', 'open');
        $sme = $this->makeIpo('Small Board Ltd.', 'SME', 'open');

        Livewire::test(ListIpos::class)
            ->set('activeTab', 'sme')
            ->assertCanSeeTableRecords([$sme])
            ->assertCanNotSeeTableRecords([$main]);
    }

    /** The list opens on what can still be acted on, not years of history. */
    public function test_the_default_tab_shows_only_open_and_upcoming_issues(): void
    {
        $this->actingAs($this->user);

        $open = $this->makeIpo('Open Ltd.', 'EQ', 'open');
        $upcoming = $this->makeIpo('Upcoming Ltd.', 'EQ', 'upcoming');
        $listed = $this->makeIpo('Old Ltd.', 'EQ', 'listed');

        Livewire::test(ListIpos::class)
            ->assertCanSeeTableRecords([$open, $upcoming])
            ->assertCanNotSeeTableRecords([$listed]);
    }

    public function test_the_all_tab_shows_everything(): void
    {
        $this->actingAs($this->user);

        $open = $this->makeIpo('Open Ltd.', 'EQ', 'open');
        $listed = $this->makeIpo('Old Ltd.', 'SME', 'listed');

        Livewire::test(ListIpos::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$open, $listed]);
    }

    public function test_the_list_shows_the_premium_and_the_retail_figure(): void
    {
        $this->actingAs($this->user);

        Ipo::withoutGlobalScope('tenant')->create([
            'user_id' => $this->user->id,
            'name' => 'Skyways Air Services Ltd.',
            'issue_type' => 'EQ',
            'status' => 'open',
            'price_per_share' => 138,
            'lot_size' => 100,
            'gmp' => 46,
            'gmp_percent' => 33.33,
            'subscription_retail' => 12.88,
        ]);

        Livewire::test(ListIpos::class)
            ->assertSee('₹46.00', false)
            ->assertSee('33.33%')
            ->assertSee('12.88x');
    }

    // ---- Default ordering: open first, most urgent first -----------------

    /**
     * Open issues are the ones that can still be acted on today, so they lead
     * the list — ordered by closing date, since the one closing soonest needs
     * a decision first. Upcoming issues follow the same way by opening date.
     * Decided issues (closed/allotted/listed) trail behind, newest first.
     */
    public function test_open_issues_lead_the_list_ordered_by_how_soon_they_close(): void
    {
        $this->actingAs($this->user);

        $closesLater = Ipo::withoutGlobalScope('tenant')->create([
            'user_id' => $this->user->id, 'name' => 'Closes Later Ltd.',
            'status' => 'open', 'open_date' => '2026-08-20', 'close_date' => '2026-09-05',
        ]);
        $closesSoon = Ipo::withoutGlobalScope('tenant')->create([
            'user_id' => $this->user->id, 'name' => 'Closes Soon Ltd.',
            'status' => 'open', 'open_date' => '2026-08-25', 'close_date' => '2026-08-29',
        ]);
        $upcoming = Ipo::withoutGlobalScope('tenant')->create([
            'user_id' => $this->user->id, 'name' => 'Upcoming Ltd.',
            'status' => 'upcoming', 'open_date' => '2026-09-01',
        ]);
        $listed = Ipo::withoutGlobalScope('tenant')->create([
            'user_id' => $this->user->id, 'name' => 'Already Listed Ltd.',
            'status' => 'listed', 'open_date' => '2026-06-01',
        ]);

        $ids = Livewire::test(ListIpos::class)
            ->set('activeTab', 'all')
            ->instance()
            ->getFilteredSortedTableQuery()
            ->pluck('id')
            ->all();

        $order = array_flip($ids);

        $this->assertLessThan($order[$closesLater->id], $order[$closesSoon->id]);
        $this->assertLessThan($order[$upcoming->id], $order[$closesLater->id]);
        $this->assertLessThan($order[$listed->id], $order[$upcoming->id]);
    }

    /** Clicking a column header must override the built-in urgency order. */
    public function test_clicking_a_column_header_overrides_the_default_order(): void
    {
        $this->actingAs($this->user);

        $a = $this->makeIpo('Alpha Ltd.', 'EQ', 'open');
        $b = $this->makeIpo('Zeta Ltd.', 'EQ', 'open');

        $component = Livewire::test(ListIpos::class)
            ->set('activeTab', 'all')
            ->sortTable('name', 'asc');

        $ids = $component->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame([$a->id, $b->id], array_values(array_intersect($ids, [$a->id, $b->id])));
    }
}
