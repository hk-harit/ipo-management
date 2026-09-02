<?php

namespace Tests\Feature;

use App\Models\Ipo;
use App\Models\User;
use App\Services\IpoFeeds\ChittorgarhFeed;
use App\Services\IpoFeeds\FeedException;
use App\Services\IpoFeeds\IpoAlertsClient;
use App\Services\IpoFeeds\IpoAlertsFeed;
use App\Services\IpoFeeds\IpoSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChittorgarhFeedTest extends TestCase
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

    /** One row shaped exactly as the financial-year report returns it. */
    private function reportRow(array $overrides = []): array
    {
        return array_replace([
            'Company' => '<a href="https://www.chittorgarh.com/ipo/deepa-jewellers-ipo/2827/" title="Deepa Jewellers IPO Details">Deepa Jewellers Ltd.</a> ',
            'Issue Category' => 'Mainboard',
            'Pricing Method' => 'Bookbuilding',
            'Opening Date' => '01-Sep-2026',
            'Closing Date' => '03-Sep-2026',
            'Listing Date' => '',
            'Issue Price (Rs.)' => '168.00 to 177.00',
            'Issue Amount (Rs.cr.)' => '459.72',
            '~IPO' => 'Deepa Jewellers IPO',
            '~URLRewrite_Folder_Name' => 'deepa-jewellers-ipo',
            '~Issue_Open_Date' => '2026-09-01T00:00:00.000Z',
            '~IssueCloseDate' => '2026-09-03T00:00:00.000Z',
            '~ListingDate' => '2026-09-08T00:00:00.000Z',
            '~compare_image' => 'https://www.chittorgarh.net/images/ipo/deepa-jewellers-ipo-logo.png',
            'Listing at' => 'BSE, NSE',
        ], $overrides);
    }

    /** One row shaped as the current-window list returns it. */
    private function listRow(array $overrides = []): array
    {
        return array_replace([
            'id' => 2827,
            'urlrewrite_folder_name' => 'deepa-jewellers-ipo',
            'company_name' => 'Deepa Jewellers Ltd.',
            'ipo_status' => 'Current',
            'market_lot_size' => 84,
            'issue_withdraw' => 0,
        ], $overrides);
    }

    /** Mapping only — no HTTP, so several rows can be mapped in one test. */
    private function map(array $rows, array $window = []): array
    {
        return (new ChittorgarhFeed)->normaliseAll($rows, $window);
    }

    /** Both chittorgarh endpoints stubbed for a full fetch(). */
    private function fakeEndpoints(array $reportRows, array $listRows = []): void
    {
        Http::fake([
            'webnodejs.chittorgarh.com/cloud/report/*' => Http::response([
                'msg' => 1,
                'totalRecords' => count($reportRows),
                'totalPages' => 1,
                'reportTableData' => $reportRows,
            ]),
            'webnodejs.chittorgarh.com/cloud/index/ipo-read/*' => Http::response([
                'msg' => 1,
                'ipoList' => $listRows,
            ]),
        ]);
    }

    public function test_it_maps_a_report_row_into_the_normalised_shape(): void
    {
        $ipo = $this->map([$this->reportRow()])[0];

        $this->assertSame('chittorgarh:2827', $ipo['external_id']);
        $this->assertSame('Deepa Jewellers Ltd.', $ipo['name']);
        $this->assertSame('EQ', $ipo['issue_type']);
        $this->assertSame('2026-09-01', $ipo['open_date']);
        $this->assertSame('2026-09-03', $ipo['close_date']);
        $this->assertSame('2026-09-08', $ipo['listing_date']);
        $this->assertSame(168.0, $ipo['price_min']);
        $this->assertSame(177.0, $ipo['price_max']);
        $this->assertSame(177.0, $ipo['price_per_share']);
        $this->assertSame('459.72cr', $ipo['issue_size']);
        $this->assertSame('https://www.chittorgarh.com/ipo/deepa-jewellers-ipo/2827/', $ipo['info_url']);
    }

    /**
     * Company arrives as markup, and an open issue has a status badge glued on
     * after the anchor. Stripping tags wholesale would leave "Ltd. O".
     */
    public function test_the_company_name_is_taken_from_the_anchor_not_the_whole_cell(): void
    {
        $ipo = $this->map([$this->reportRow([
            'Company' => '<a href="https://www.chittorgarh.com/ipo/annu-projects-ipo/2801/">Annu Projects Ltd.</a> <span class="badge bg-success" title="Open">O</span>',
        ])])[0];

        $this->assertSame('Annu Projects Ltd.', $ipo['name']);
        $this->assertSame('chittorgarh:2801', $ipo['external_id']);
    }

    public function test_a_price_band_is_split_into_low_and_high(): void
    {
        $ipo = $this->map([$this->reportRow(['Issue Price (Rs.)' => '94.00 to 99.00'])])[0];

        $this->assertSame(94.0, $ipo['price_min']);
        $this->assertSame(99.0, $ipo['price_max']);
    }

    public function test_a_single_fixed_price_fills_both_ends_of_the_band(): void
    {
        $ipo = $this->map([$this->reportRow(['Issue Price (Rs.)' => '59.00'])])[0];

        $this->assertSame(59.0, $ipo['price_min']);
        $this->assertSame(59.0, $ipo['price_max']);
    }

    public function test_an_sme_issue_is_recognised(): void
    {
        $ipo = $this->map([$this->reportRow([
            'Issue Category' => 'SME',
            'Listing at' => 'BSE SME',
        ])])[0];

        $this->assertSame('SME', $ipo['issue_type']);
    }

    /** The report has no status column, so the dates have to carry it. */
    public function test_status_is_derived_from_the_dates_when_the_report_is_all_we_have(): void
    {
        $cases = [
            // open, close, listing => expected
            ['2026-09-01', '2026-09-03', '', 'upcoming'],   // not open yet
            ['2026-08-25', '2026-08-28', '', 'open'],       // today is 27 Aug
            ['2026-08-27', '2026-08-29', '', 'open'],       // opens today
            ['2026-08-10', '2026-08-20', '', 'closed'],     // already shut
            ['2026-08-01', '2026-08-05', '2026-08-12', 'listed'],
        ];

        foreach ($cases as [$open, $close, $listing, $expected]) {
            $ipo = $this->map([$this->reportRow([
                '~Issue_Open_Date' => $open,
                '~IssueCloseDate' => $close,
                '~ListingDate' => $listing,
            ])])[0];

            $this->assertSame($expected, $ipo['status'], "$open..$close should be $expected");
        }
    }

    /** A listing date still in the future must not mark the IPO listed. */
    public function test_a_future_listing_date_does_not_mark_an_ipo_listed(): void
    {
        $ipo = $this->map([$this->reportRow([
            '~Issue_Open_Date' => '2026-08-20',
            '~IssueCloseDate' => '2026-08-22',
            '~ListingDate' => '2026-09-08',
        ])])[0];

        $this->assertSame('closed', $ipo['status']);
    }

    /** Lot size lives only on the current-window list, keyed by slug. */
    public function test_the_current_window_supplies_the_lot_size_and_status(): void
    {
        $window = ['deepa-jewellers-ipo' => ['lot_size' => 84, 'status' => 'open']];

        $ipo = $this->map([$this->reportRow()], $window)[0];

        $this->assertSame(84, $ipo['lot_size']);
        $this->assertSame('open', $ipo['status']);
        // 84 shares at the top of the band.
        $this->assertSame(14868.0, $ipo['min_amount']);
    }

    public function test_a_row_with_no_company_is_dropped(): void
    {
        $this->assertSame([], $this->map([$this->reportRow(['Company' => ''])]));
    }

    public function test_it_pulls_both_mainboard_and_sme_reports(): void
    {
        $this->fakeEndpoints([$this->reportRow()], [$this->listRow()]);

        $ipos = (new ChittorgarhFeed)->fetch();

        // One report row per category, both the same IPO here.
        $this->assertCount(2, $ipos);
        $this->assertSame(84, $ipos[0]['lot_size']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/mainboard/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sme/'));
    }

    /** August 2026 falls in the Indian financial year 2026-27. */
    public function test_it_asks_for_the_current_financial_year(): void
    {
        $this->fakeEndpoints([]);

        (new ChittorgarhFeed)->fetch();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/2026-27/'));
    }

    /** January still belongs to the financial year that started the April before. */
    public function test_january_asks_for_the_financial_year_that_started_last_april(): void
    {
        Carbon::setTestNow('2027-01-15');
        $this->fakeEndpoints([]);

        (new ChittorgarhFeed)->fetch();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/2026-27/'));
    }

    /** Lot sizes are a bonus. Losing them must not cost us the whole listing. */
    public function test_the_report_still_returns_when_the_current_window_call_fails(): void
    {
        Http::fake([
            'webnodejs.chittorgarh.com/cloud/report/*' => Http::response([
                'msg' => 1, 'totalPages' => 1, 'reportTableData' => [$this->reportRow()],
            ]),
            'webnodejs.chittorgarh.com/cloud/index/*' => Http::response('down', 503),
            'webnodejs.investorgain.com/*' => Http::response(['msg' => 1, 'reportTableData' => []]),
        ]);

        $ipos = (new ChittorgarhFeed)->fetch();

        $this->assertCount(2, $ipos);
        $this->assertArrayNotHasKey('lot_size', $ipos[0]);
    }

    public function test_an_http_failure_on_the_report_is_reported(): void
    {
        Http::fake(['webnodejs.chittorgarh.com/*' => Http::response('nope', 503)]);

        $this->expectException(FeedException::class);
        $this->expectExceptionMessage('HTTP 503');

        (new ChittorgarhFeed)->fetch();
    }

    public function test_an_unexpected_response_is_reported_rather_than_swallowed(): void
    {
        Http::fake(['webnodejs.chittorgarh.com/*' => Http::response('<html>nope</html>', 200)]);

        $this->expectException(FeedException::class);
        $this->expectExceptionMessage('unexpected response');

        (new ChittorgarhFeed)->fetch();
    }

    public function test_the_feed_can_be_switched_off(): void
    {
        $this->assertFalse((new ChittorgarhFeed(enabled: false))->isConfigured());
    }

    public function test_it_syncs_into_ipos(): void
    {
        $this->fakeEndpoints([$this->reportRow()], [$this->listRow()]);

        $result = (new IpoSync(new ChittorgarhFeed))->syncFor($this->user);

        $this->assertSame(1, $result->created);

        $ipo = Ipo::withoutGlobalScope('tenant')->first();
        $this->assertSame('Deepa Jewellers Ltd.', $ipo->name);
        $this->assertSame('2026-09-01', $ipo->open_date->toDateString());
        $this->assertSame('177.00', $ipo->price_max);
        $this->assertSame(84, $ipo->lot_size);
    }

    /**
     * The point of running two feeds: one IPO, one row, each source filling in
     * what the other is missing.
     */
    public function test_the_same_ipo_from_both_feeds_lands_as_one_row(): void
    {
        Http::fake([
            'api.ipoalerts.in/*' => Http::response(['meta' => ['totalPages' => 1], 'ipos' => [[
                'id' => '999',
                'name' => 'Deepa Jewellers Ltd.',
                'symbol' => 'DEEPA',
                'status' => 'upcoming',
                'startDate' => '2026-09-01',
                'endDate' => '2026-09-03',
                'priceRange' => '168-177',
                'prospectusUrl' => 'https://example.test/rhp.pdf',
            ]]]),
            'webnodejs.chittorgarh.com/cloud/report/*' => Http::response([
                'msg' => 1, 'totalPages' => 1, 'reportTableData' => [$this->reportRow()],
            ]),
            'webnodejs.chittorgarh.com/cloud/index/*' => Http::response([
                'msg' => 1, 'ipoList' => [$this->listRow()],
            ]),
        ]);

        $result = (new IpoSync(
            new IpoAlertsFeed(new IpoAlertsClient(apiKey: 'k')),
            new ChittorgarhFeed,
        ))->syncFor($this->user);

        $this->assertSame(1, $result->created);
        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->count());

        $ipo = Ipo::withoutGlobalScope('tenant')->first();

        // ipoalerts runs first, so it wins the fields both carry...
        $this->assertSame('ipoalerts:999', $ipo->external_id);
        $this->assertSame('DEEPA', $ipo->symbol);
        $this->assertSame('https://example.test/rhp.pdf', $ipo->prospectus_url);

        // ...and chittorgarh fills in what ipoalerts never sent.
        $this->assertSame(84, $ipo->lot_size);
        $this->assertSame('459.72cr', $ipo->issue_size);
        $this->assertStringContainsString('chittorgarh.com', $ipo->info_url);
    }

    /**
     * The feeds spell companies differently — "Priority Jewels Limited" against
     * "Priority Jewels Ltd." — and matching on the raw name let that through as
     * two rows for one IPO.
     */
    public function test_ltd_and_limited_are_recognised_as_the_same_company(): void
    {
        Http::fake([
            'api.ipoalerts.in/*' => Http::response(['meta' => ['totalPages' => 1], 'ipos' => [[
                'id' => '999',
                'name' => 'Priority Jewels Limited',
                'symbol' => 'PRIORITY',
                'status' => 'upcoming',
                'startDate' => '2026-08-28',
                'endDate' => '2026-09-01',
                'priceRange' => '190-200',
            ]]]),
            'webnodejs.chittorgarh.com/cloud/report/*' => Http::response([
                'msg' => 1, 'totalPages' => 1, 'reportTableData' => [$this->reportRow([
                    'Company' => '<a href="https://www.chittorgarh.com/ipo/priority-jewels-ipo/2757/">Priority Jewels Ltd.</a>',
                    '~URLRewrite_Folder_Name' => 'priority-jewels-ipo',
                    'Issue Amount (Rs.cr.)' => '91.50',
                ])],
            ]),
            'webnodejs.chittorgarh.com/cloud/index/*' => Http::response(['msg' => 1, 'ipoList' => []]),
            'webnodejs.investorgain.com/*' => Http::response(['msg' => 1, 'reportTableData' => []]),
        ]);

        $result = IpoSync::fromConfig()->syncFor($this->user);

        $this->assertSame(1, $result->created);
        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->count());

        $ipo = Ipo::withoutGlobalScope('tenant')->first();
        $this->assertSame('PRIORITY', $ipo->symbol);
        $this->assertSame('91.5cr', $ipo->issue_size);
    }

    /** A bracketed trading alias must not stop the two feeds agreeing either. */
    public function test_a_bracketed_alias_does_not_split_an_ipo_in_two(): void
    {
        Http::fake([
            'api.ipoalerts.in/*' => Http::response(['meta' => ['totalPages' => 1], 'ipos' => [[
                'id' => '998',
                'name' => 'Purple Style Labs Limited',
                'symbol' => 'PSL',
                'status' => 'upcoming',
                'startDate' => '2026-08-31',
                'endDate' => '2026-09-02',
            ]]]),
            'webnodejs.chittorgarh.com/cloud/report/*' => Http::response([
                'msg' => 1, 'totalPages' => 1, 'reportTableData' => [$this->reportRow([
                    'Company' => '<a href="https://www.chittorgarh.com/ipo/pernias-pop-up-studio-ipo/2622/">Purple Style Labs Ltd. (Pernia\'s Pop-up Studio)</a>',
                    '~URLRewrite_Folder_Name' => 'pernias-pop-up-studio-ipo',
                ])],
            ]),
            'webnodejs.chittorgarh.com/cloud/index/*' => Http::response(['msg' => 1, 'ipoList' => []]),
            'webnodejs.investorgain.com/*' => Http::response(['msg' => 1, 'reportTableData' => []]),
        ]);

        IpoSync::fromConfig()->syncFor($this->user);

        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->count());
    }

    /** Two rows for the same IPO inside a single run must not both be created. */
    public function test_the_same_company_twice_in_one_batch_creates_one_row(): void
    {
        $this->fakeEndpoints([
            $this->reportRow(),
            $this->reportRow([
                'Company' => '<a href="https://www.chittorgarh.com/ipo/deepa-jewellers-ipo/9999/">Deepa Jewellers Limited</a>',
                '~URLRewrite_Folder_Name' => 'deepa-jewellers-ipo-2',
            ]),
        ]);

        $result = (new IpoSync(new ChittorgarhFeed))->syncFor($this->user);

        $this->assertSame(1, $result->created);
        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->count());
    }

    /** Different companies that merely share a word must stay apart. */
    public function test_genuinely_different_companies_are_not_merged(): void
    {
        $this->fakeEndpoints([
            $this->reportRow(),
            $this->reportRow([
                'Company' => '<a href="https://www.chittorgarh.com/ipo/deepa-industries-ipo/3000/">Deepa Industries Ltd.</a>',
                '~URLRewrite_Folder_Name' => 'deepa-industries-ipo',
            ]),
        ]);

        $result = (new IpoSync(new ChittorgarhFeed))->syncFor($this->user);

        $this->assertSame(2, $result->created);
    }

    public function test_an_ipo_only_one_feed_knows_about_is_still_synced(): void
    {
        Http::fake([
            'api.ipoalerts.in/*' => Http::response(['meta' => ['totalPages' => 1], 'ipos' => []]),
            'webnodejs.chittorgarh.com/cloud/report/*' => Http::response([
                'msg' => 1, 'totalPages' => 1, 'reportTableData' => [
                    $this->reportRow(),
                    $this->reportRow([
                        'Company' => '<a href="https://www.chittorgarh.com/ipo/rays-of-belief-ipo/2787/">Rays of Belief Ltd.</a>',
                        '~URLRewrite_Folder_Name' => 'rays-of-belief-ipo',
                    ]),
                ],
            ]),
            'webnodejs.chittorgarh.com/cloud/index/*' => Http::response(['msg' => 1, 'ipoList' => []]),
            'webnodejs.investorgain.com/*' => Http::response(['msg' => 1, 'reportTableData' => []]),
        ]);

        $result = IpoSync::fromConfig()->syncFor($this->user);

        $this->assertSame(2, $result->created);
        $this->assertSame(2, Ipo::withoutGlobalScope('tenant')->count());
    }
}
