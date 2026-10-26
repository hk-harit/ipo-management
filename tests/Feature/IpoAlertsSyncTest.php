<?php

namespace Tests\Feature;

use App\Filament\Resources\IpoResource\Pages\ListIpos;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\User;
use App\Services\IpoFeeds\FeedException;
use App\Services\IpoFeeds\IpoAlertsClient;
use App\Services\IpoFeeds\IpoAlertsFeed;
use App\Services\IpoFeeds\IpoSync;
use App\Services\IpoFeeds\IpoSyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class IpoAlertsSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // The IPO list opens in box view by default; these tests read the table.
        $this->withSession(['ipo_view_mode' => 'table']);

        $this->user = User::factory()->create();
        Http::preventStrayRequests();

        // Fixture payloads below use fixed 2026-08/09 dates so every assertion
        // reads as a plain literal. IpoSync derives status from those dates
        // against "today" (see statusFromDates()), so "today" has to stay
        // pinned safely before all of them — otherwise the suite starts
        // failing the moment the wall clock catches up to the fixtures.
        Carbon::setTestNow('2026-08-20');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** One IPO shaped exactly as ipoalerts.in returns it. */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'id' => '2057446987',
            'source' => 'bse',
            'status' => 'upcoming',
            'slug' => 'priority',
            'name' => 'Priority Jewels Limited',
            'symbol' => 'PRIORITY',
            'type' => 'EQ',
            'startDate' => '2026-08-28',
            'endDate' => '2026-09-01',
            'listingDate' => '2026-09-04',
            'priceRange' => '190-200',
            'minQty' => 75,
            'minAmount' => 15000,
            'issueSize' => '92cr',
            'logo' => 'https://example.test/logo.png',
            'infoUrl' => 'https://example.test/ipo',
            'prospectusUrl' => 'https://example.test/rhp.pdf',
            'about' => 'A jewellery manufacturer.',
            'schedule' => [
                ['event' => 'Issue open date', 'date' => '2026-08-28'],
                ['event' => 'Allotment finalization', 'date' => '2026-09-01'],
                ['event' => 'Listing date', 'date' => '2026-09-04'],
            ],
        ], $overrides);
    }

    /** One row shaped as chittorgarh's financial-year report returns it. */
    private function chittorgarhRow(array $overrides = []): array
    {
        return array_replace([
            'Company' => '<a href="https://www.chittorgarh.com/ipo/deepa-jewellers-ipo/2827/">Deepa Jewellers Ltd.</a>',
            'Issue Category' => 'Mainboard',
            'Issue Price (Rs.)' => '168.00 to 177.00',
            '~URLRewrite_Folder_Name' => 'deepa-jewellers-ipo',
            '~Issue_Open_Date' => '2026-09-01T00:00:00.000Z',
            '~IssueCloseDate' => '2026-09-03T00:00:00.000Z',
            '~ListingDate' => '',
            'Listing at' => 'BSE, NSE',
        ], $overrides);
    }

    /**
     * Runs the payloads through ipoalerts' own normalisation and into the DB,
     * without HTTP — Http::fake() merges rather than replaces its stubs, so a
     * test that syncs twice cannot simply re-fake between the two runs.
     */
    private function sync(array $payloads): IpoSyncResult
    {
        $feed = new IpoAlertsFeed(new IpoAlertsClient(apiKey: 'test-key'));

        return (new IpoSync($feed))->apply($this->user, $feed->normaliseAll($payloads));
    }

    public function test_it_creates_an_ipo_from_the_feed_with_every_detail_mapped(): void
    {
        $result = $this->sync([$this->payload()]);

        $this->assertSame(1, $result->created);

        $ipo = Ipo::withoutGlobalScope('tenant')->first();

        $this->assertSame('Priority Jewels Limited', $ipo->name);
        $this->assertSame('ipoalerts:2057446987', $ipo->external_id);
        $this->assertSame('PRIORITY', $ipo->symbol);
        $this->assertSame('EQ', $ipo->issue_type);
        $this->assertSame('upcoming', $ipo->status);
        $this->assertSame('2026-08-28', $ipo->open_date->toDateString());
        $this->assertSame('2026-09-01', $ipo->close_date->toDateString());
        $this->assertSame('2026-09-04', $ipo->listing_date->toDateString());
        // Pulled out of the schedule array, not a top-level field.
        $this->assertSame('2026-09-01', $ipo->allotment_date->toDateString());
        $this->assertSame('190.00', $ipo->price_min);
        $this->assertSame('200.00', $ipo->price_max);
        $this->assertSame('200.00', $ipo->price_per_share);
        $this->assertSame(75, $ipo->lot_size);
        $this->assertSame('92cr', $ipo->issue_size);
        $this->assertSame($this->user->id, $ipo->user_id);
        $this->assertNotNull($ipo->synced_at);
    }

    public function test_a_single_price_band_becomes_one_price(): void
    {
        $this->sync([$this->payload(['priceRange' => '10-10'])]);

        $ipo = Ipo::withoutGlobalScope('tenant')->first();

        $this->assertSame('10.00', $ipo->price_min);
        $this->assertSame('10.00', $ipo->price_max);
        $this->assertSame('₹10.00', $ipo->price_band);
    }

    public function test_a_price_band_renders_as_a_range(): void
    {
        $this->sync([$this->payload()]);

        $this->assertSame('₹190.00 – ₹200.00', Ipo::withoutGlobalScope('tenant')->first()->price_band);
    }

    public function test_running_the_sync_twice_updates_rather_than_duplicates(): void
    {
        $this->sync([$this->payload()]);
        $result = $this->sync([$this->payload()]);

        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->count());
        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->unchanged);
    }

    public function test_a_changed_detail_is_picked_up_on_the_next_run(): void
    {
        $this->sync([$this->payload()]);

        $result = $this->sync([$this->payload(['endDate' => '2026-09-02', 'priceRange' => '190-210'])]);

        $this->assertSame(1, $result->updated);

        $ipo = Ipo::withoutGlobalScope('tenant')->first();
        $this->assertSame('2026-09-02', $ipo->close_date->toDateString());
        $this->assertSame('210.00', $ipo->price_max);
    }

    public function test_the_feed_moves_an_ipo_forward_through_the_statuses(): void
    {
        $this->sync([$this->payload(['status' => 'upcoming'])]);
        $this->assertSame('upcoming', Ipo::withoutGlobalScope('tenant')->first()->status);

        $this->sync([$this->payload(['status' => 'open'])]);
        $this->assertSame('open', Ipo::withoutGlobalScope('tenant')->first()->status);

        $this->sync([$this->payload(['status' => 'closed'])]);
        $this->assertSame('closed', Ipo::withoutGlobalScope('tenant')->first()->status);
    }

    /**
     * The one that matters most. Once the user marks an IPO allotted, a feed
     * still calling it "closed" must not drag their bookkeeping backwards.
     */
    public function test_the_feed_never_drags_a_status_backwards(): void
    {
        $this->sync([$this->payload(['status' => 'closed'])]);

        $ipo = Ipo::withoutGlobalScope('tenant')->first();
        $ipo->forceFill(['status' => 'allotted'])->save();

        $this->sync([$this->payload(['status' => 'closed'])]);

        $this->assertSame('allotted', $ipo->fresh()->status);
    }

    public function test_the_users_own_notes_are_never_overwritten(): void
    {
        $this->sync([$this->payload()]);

        $ipo = Ipo::withoutGlobalScope('tenant')->first();
        $ipo->forceFill(['notes' => 'Applied for 3 lots from Papa account'])->save();

        $this->sync([$this->payload(['endDate' => '2026-09-05'])]);

        $this->assertSame('Applied for 3 lots from Papa account', $ipo->fresh()->notes);
    }

    public function test_an_ipo_typed_in_by_hand_is_adopted_instead_of_duplicated(): void
    {
        $manual = Ipo::withoutGlobalScope('tenant')->create([
            'user_id' => $this->user->id,
            'name' => '  priority jewels limited ',   // sloppy casing and spacing
            'status' => 'upcoming',
        ]);

        $result = $this->sync([$this->payload()]);

        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->count());
        $this->assertSame(1, $result->updated);
        $this->assertSame('ipoalerts:2057446987', $manual->fresh()->external_id);
    }

    public function test_applications_already_attached_to_an_ipo_survive_a_sync(): void
    {
        $this->sync([$this->payload()]);
        $ipo = Ipo::withoutGlobalScope('tenant')->first();

        $account = Account::create(['user_id' => $this->user->id, 'name' => 'Bank', 'type' => 'bank']);
        $applicant = Applicant::create(['user_id' => $this->user->id, 'name' => 'Friend']);

        IpoApplication::create([
            'user_id' => $this->user->id,
            'ipo_id' => $ipo->id,
            'applicant_id' => $applicant->id,
            'account_id' => $account->id,
            'amount' => 15000,
            'applied_date' => now(),
            'status' => 'applied',
        ]);

        $this->sync([$this->payload(['status' => 'closed'])]);

        $this->assertSame(1, IpoApplication::withoutGlobalScope('tenant')->count());
        $this->assertSame($ipo->id, IpoApplication::withoutGlobalScope('tenant')->first()->ipo_id);
    }

    public function test_a_payload_with_no_id_or_no_name_never_reaches_the_database(): void
    {
        $feed = new IpoAlertsFeed(new IpoAlertsClient(apiKey: 'k'));

        // The feed drops them at the mapping stage...
        $rows = $feed->normaliseAll([
            $this->payload(['id' => '']),
            $this->payload(['id' => '999', 'name' => '']),
        ]);

        $this->assertSame([], $rows);

        // ...and the sync refuses them again if one somehow gets that far.
        $result = (new IpoSync($feed))->apply($this->user, [
            ['name' => 'No id at all'],
            ['external_id' => 'x:1'],
        ]);

        $this->assertSame(0, $result->created);
        $this->assertSame(2, $result->skipped);
        $this->assertSame(0, Ipo::withoutGlobalScope('tenant')->count());
    }

    public function test_an_announced_ipo_with_no_dates_still_lands_as_upcoming(): void
    {
        $this->sync([[
            'id' => '888363609',
            'status' => 'announced',
            'name' => 'VLCC Health Care',
            'issueSize' => '–',
            'schedule' => [],
        ]]);

        $ipo = Ipo::withoutGlobalScope('tenant')->first();

        $this->assertSame('upcoming', $ipo->status);
        $this->assertNull($ipo->open_date);
        // "–" is the feed's way of saying "unknown", not a real issue size.
        $this->assertNull($ipo->issue_size);
    }

    public function test_two_users_each_get_their_own_copy_of_the_listing(): void
    {
        $other = User::factory()->create();

        Http::fake(['api.ipoalerts.in/*' => Http::response([
            'meta' => ['totalPages' => 1], 'ipos' => [$this->payload()],
        ])]);

        $feed = new IpoAlertsFeed(new IpoAlertsClient(apiKey: 'k'));
        $rows = $feed->fetch();

        $sync = new IpoSync($feed);
        $sync->apply($this->user, $rows);
        $sync->apply($other, $rows);

        $this->assertSame(2, Ipo::withoutGlobalScope('tenant')->count());

        $this->actingAs($this->user);
        $this->assertSame(1, Ipo::count());
    }

    public function test_the_client_follows_pagination_to_the_end(): void
    {
        Http::fake([
            'api.ipoalerts.in/ipos?status=open&page=1' => Http::response([
                'meta' => ['totalPages' => 2],
                'ipos' => [$this->payload(['id' => '1'])],
            ]),
            'api.ipoalerts.in/ipos?status=open&page=2' => Http::response([
                'meta' => ['totalPages' => 2],
                'ipos' => [$this->payload(['id' => '2'])],
            ]),
        ]);

        $ipos = (new IpoAlertsClient(apiKey: 'test-key'))->fetch('open');

        $this->assertCount(2, $ipos);
        Http::assertSentCount(2);
    }

    public function test_the_api_key_is_sent_as_a_header(): void
    {
        Http::fake(['api.ipoalerts.in/*' => Http::response(['meta' => ['totalPages' => 1], 'ipos' => []])]);

        (new IpoAlertsClient(apiKey: 'my-secret-key'))->fetch('open');

        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'my-secret-key'));
    }

    public function test_an_invalid_key_is_reported_rather_than_swallowed(): void
    {
        Http::fake(['api.ipoalerts.in/*' => Http::response([
            'error' => 'The provided API key is invalid. Please check and try again. (ERR:INVLDKEY)',
        ])]);

        $this->expectException(FeedException::class);
        $this->expectExceptionMessage('The provided API key is invalid');

        (new IpoAlertsClient(apiKey: 'wrong'))->fetch('open');
    }

    public function test_being_rate_limited_is_reported_clearly(): void
    {
        Http::fake(['api.ipoalerts.in/*' => Http::response(['title' => 'Too Many Requests'], 429)]);

        $this->expectException(FeedException::class);
        $this->expectExceptionMessage('rate limiting');

        (new IpoAlertsClient)->fetch('open');
    }

    public function test_the_same_ipo_appearing_under_two_statuses_is_only_carried_once(): void
    {
        Http::fake([
            'api.ipoalerts.in/*' => Http::response([
                'meta' => ['totalPages' => 1],
                'ipos' => [$this->payload()],
            ]),
        ]);

        $ipos = (new IpoAlertsClient(apiKey: 'k'))->fetchAll();

        // Four statuses queried, one IPO returned by each, all the same id.
        $this->assertCount(1, $ipos);
        Http::assertSentCount(4);
    }

    /** Both live feeds stubbed, as a real run through IpoSync::fromConfig() hits both. */
    private function fakeBothFeeds(array $ipoalerts = [], array $chittorgarh = []): void
    {
        Http::fake([
            'api.ipoalerts.in/*' => Http::response(['meta' => ['totalPages' => 1], 'ipos' => $ipoalerts]),
            'webnodejs.chittorgarh.com/cloud/report/*' => Http::response([
                'msg' => 1, 'totalPages' => 1, 'reportTableData' => $chittorgarh,
            ]),
            'webnodejs.chittorgarh.com/cloud/index/*' => Http::response(['msg' => 1, 'ipoList' => []]),
            'webnodejs.investorgain.com/*' => Http::response(['msg' => 1, 'reportTableData' => []]),
        ]);
    }

    public function test_the_console_command_syncs_every_user(): void
    {
        $other = User::factory()->create();

        $this->fakeBothFeeds([$this->payload()]);

        $this->artisan('ipos:sync')
            ->expectsOutputToContain('1 IPOs came back')
            ->assertSuccessful();

        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->where('user_id', $this->user->id)->count());
        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->where('user_id', $other->id)->count());
    }

    public function test_the_command_fails_only_when_every_feed_is_down(): void
    {
        Http::fake([
            'api.ipoalerts.in/*' => Http::response(['error' => 'nope']),
            'webnodejs.chittorgarh.com/*' => Http::response('down', 503),
            'webnodejs.investorgain.com/*' => Http::response('down', 503),
        ]);

        $this->artisan('ipos:sync')->assertFailed();
    }

    /** One dead feed must not cost the user the other one. */
    public function test_the_command_carries_on_when_only_one_feed_is_down(): void
    {
        Http::fake([
            'api.ipoalerts.in/*' => Http::response(['error' => 'The provided API key is invalid.']),
            'webnodejs.chittorgarh.com/cloud/report/*' => Http::response([
                'msg' => 1, 'totalPages' => 1, 'reportTableData' => [$this->chittorgarhRow()],
            ]),
            'webnodejs.chittorgarh.com/cloud/index/*' => Http::response(['msg' => 1, 'ipoList' => []]),
            'webnodejs.investorgain.com/*' => Http::response(['msg' => 1, 'reportTableData' => []]),
        ]);

        $this->artisan('ipos:sync')->assertSuccessful();

        $this->assertSame(1, Ipo::withoutGlobalScope('tenant')->count());
        $this->assertSame('Deepa Jewellers Ltd.', Ipo::withoutGlobalScope('tenant')->first()->name);
    }

    public function test_the_sync_button_on_the_ipo_list_pulls_the_listing_in(): void
    {
        $this->actingAs($this->user);
        $this->fakeBothFeeds([$this->payload()]);

        Livewire::test(ListIpos::class)
            ->callAction('sync')
            ->assertNotified();

        $this->assertSame(1, Ipo::count());
        $this->assertSame('Priority Jewels Limited', Ipo::first()->name);
    }

    public function test_the_sync_button_reports_a_failure_instead_of_blowing_up(): void
    {
        $this->actingAs($this->user);

        Http::fake([
            'api.ipoalerts.in/*' => Http::response([
                'error' => 'The provided API key is invalid. Please check and try again. (ERR:INVLDKEY)',
            ]),
            'webnodejs.chittorgarh.com/*' => Http::response('down', 503),
            'webnodejs.investorgain.com/*' => Http::response('down', 503),
        ]);

        Livewire::test(ListIpos::class)
            ->callAction('sync')
            ->assertNotified('Could not sync IPOs');

        $this->assertSame(0, Ipo::count());
    }

    public function test_the_ipo_list_page_shows_the_synced_details(): void
    {
        $this->actingAs($this->user);
        $this->sync([$this->payload()]);

        $this->get('/admin/ipos')->assertSuccessful();

        Livewire::test(ListIpos::class)
            ->assertCanSeeTableRecords(Ipo::all())
            ->assertSee('PRIORITY');
    }
}
