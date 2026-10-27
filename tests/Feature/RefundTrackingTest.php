<?php

namespace Tests\Feature;

use App\Filament\Pages\IpoDashboardPage;
use App\Filament\Pages\RefundsPage;
use App\Filament\Resources\IpoApplicationResource\Pages\CreateIpoApplication;
use App\Filament\Resources\IpoApplicationResource\Pages\ListIpoApplications;
use App\Filament\Widgets\AccountBalancesTable;
use App\Filament\Widgets\OverviewStats;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use App\Models\User;
use App\Services\AccountOverview;
use App\Services\AllotmentCheck\AllotmentChecker;
use App\Services\AllotmentCheck\AllotmentCheckResult;
use App\Services\AllotmentCheck\AllotmentCheckService;
use App\Services\AllotmentCheck\BigshareChecker;
use App\Services\DashboardSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Not allotted is not refunded: the money stays with the bank until the refund arrives, and
 * the app tracks which account it will land in and whether it has.
 */
class RefundTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Account $pilo;

    private Account $om;

    private Ipo $jio;

    private Ipo $paras;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->pilo = Account::create(['name' => 'pilo', 'type' => 'bank', 'opening_balance' => 100000]);
        $this->om = Account::create(['name' => 'Om Indus', 'type' => 'bank', 'opening_balance' => 100000]);
        $this->jio = Ipo::create(['name' => 'Jio Platforms', 'status' => 'closed', 'min_amount' => 13428, 'lot_size' => 12, 'allotment_date' => '2026-10-26']);
        $this->paras = Ipo::create(['name' => 'Paras Healthcare', 'status' => 'listed', 'min_amount' => 14553]);
    }

    private function app(string $name, Account $account, string $status, float $amount = 13428, ?Ipo $ipo = null, array $extra = []): IpoApplication
    {
        return IpoApplication::create(array_merge([
            'ipo_id' => ($ipo ?? $this->jio)->id,
            'applicant_id' => Applicant::create(['name' => $name, 'relation' => 'friend', 'pan_number' => strtoupper(substr(md5($name), 0, 5)).'1234F'])->id,
            'account_id' => $account->id,
            'amount' => $amount,
            'applied_date' => '2026-10-23',
            'status' => $status,
        ], $extra));
    }

    public function test_a_not_allotted_application_keeps_its_money_blocked_until_the_refund_arrives(): void
    {
        $this->app('Ved', $this->pilo, 'not_allotted');

        $this->assertSame(100000 - 13428.0, $this->pilo->current_balance, 'still debited');
        $this->assertSame(13428.0, $this->pilo->blocked_in_ipos, 'still held by the bank');

        $figures = (new DashboardSummary)->accountFigures()[$this->pilo->id];
        $this->assertSame(13428.0, $figures['blocked']);
        $this->assertSame(13428.0, $figures['refund_pending']);

        $totals = (new DashboardSummary)->totals();
        $this->assertSame(13428.0, $totals['refund_pending']);
        $this->assertEqualsWithDelta(200000.0, $totals['total_funds'], 0.001, 'nothing is lost while the refund is on its way');
    }

    public function test_the_refund_arriving_credits_the_account(): void
    {
        $application = $this->app('Ved', $this->pilo, 'not_allotted');

        Livewire::test(RefundsPage::class)->call('markReceived', $application->id)->assertNotified();

        $application->refresh();
        $this->assertSame('refunded', $application->status);
        $this->assertSame('13428.00', (string) $application->return_amount);
        $this->assertNotNull($application->return_date);
        $this->assertSame(100000.0, $this->pilo->current_balance);
        $this->assertSame(0.0, $this->pilo->blocked_in_ipos);
        $this->assertSame(0.0, (new DashboardSummary)->totals()['refund_pending']);
    }

    public function test_a_refund_can_come_back_a_little_short(): void
    {
        $application = $this->app('Ved', $this->pilo, 'not_allotted', 15000);

        Livewire::test(RefundsPage::class)
            ->set('amounts.'.$application->id, '14905')
            ->call('markReceived', $application->id);

        $application->refresh();
        $this->assertSame('14905.00', (string) $application->return_amount);
        $this->assertSame(95.0, $application->refund_shortfall);
        $this->assertSame(100000 - 15000 + 14905.0, $this->pilo->current_balance);
    }

    public function test_the_refund_can_never_be_more_than_what_was_applied(): void
    {
        $application = $this->app('Ved', $this->pilo, 'not_allotted');

        Livewire::test(RefundsPage::class)
            ->set('amounts.'.$application->id, '99999')
            ->call('markReceived', $application->id);

        $this->assertSame('13428.00', (string) $application->fresh()->return_amount);
    }

    public function test_refunds_are_listed_under_the_account_they_land_in_and_an_account_can_be_cleared_at_once(): void
    {
        $this->app('Ved', $this->pilo, 'not_allotted');
        $this->app('Pratik', $this->pilo, 'not_allotted');
        $this->app('Isha', $this->om, 'not_allotted');
        $this->app('Allotted One', $this->om, 'allotted', 13428, null, ['shares_allotted' => 12]);
        $this->app('Waiting', $this->om, 'applied');

        $page = Livewire::test(RefundsPage::class);
        $groups = $page->instance()->pending()->keyBy('account');

        $this->assertSame([2, 26856.0], [$groups['pilo']['count'], $groups['pilo']['total']]);
        $this->assertSame([1, 13428.0], [$groups['Om Indus']['count'], $groups['Om Indus']['total']]);
        $this->assertSame('pilo', $page->instance()->pending()->first()['account'], 'biggest first');

        $page->call('markAccountReceived', $this->pilo->id)->assertNotified();

        $this->assertSame(0, IpoApplication::where('account_id', $this->pilo->id)->where('status', 'not_allotted')->count());
        $this->assertSame(2, IpoApplication::where('account_id', $this->pilo->id)->where('status', 'refunded')->count());
        $this->assertSame(1, IpoApplication::where('account_id', $this->om->id)->where('status', 'not_allotted')->count(), 'the other account is untouched');
        $this->assertSame(100000.0, $this->pilo->current_balance);
    }

    public function test_a_sub_account_application_is_refunded_to_the_sub_account(): void
    {
        $sub = Account::create(['name' => 'Sub', 'type' => 'bank', 'opening_balance' => 50000, 'parent_id' => $this->om->id, 'is_sub_account' => true]);
        $application = $this->app('Ved', $this->om, 'not_allotted', 13428, null, ['sub_account_id' => $sub->id]);

        $groups = Livewire::test(RefundsPage::class)->instance()->pending();
        $this->assertSame('Sub', $groups->first()['account']);

        Livewire::test(RefundsPage::class)->call('markReceived', $application->id);

        $this->assertSame(50000.0, $sub->fresh()->current_balance);
        $this->assertSame(100000.0, $this->om->fresh()->current_balance, 'the main account never paid');
    }

    public function test_a_refund_marked_by_mistake_can_be_put_back(): void
    {
        $application = $this->app('Ved', $this->pilo, 'not_allotted');
        $page = Livewire::test(RefundsPage::class)->call('markReceived', $application->id);

        $page->call('undoReceived', $application->id);

        $application->refresh();
        $this->assertSame('not_allotted', $application->status);
        $this->assertNull($application->return_amount);
        $this->assertSame(100000 - 13428.0, $this->pilo->current_balance);
    }

    public function test_the_page_can_be_filtered_by_ipo_and_shows_received_refunds(): void
    {
        $this->app('Ved', $this->pilo, 'not_allotted');
        $this->app('Old', $this->pilo, 'not_allotted', 14553, $this->paras);
        $this->app('Back', $this->pilo, 'refunded', 13428, null, ['return_amount' => 13428, 'return_date' => now()->toDateString()]);

        $page = Livewire::test(RefundsPage::class)->assertOk()->assertSee('Ved')->assertSee('Old')->assertSee('Back');
        $this->assertSame(2, $page->instance()->totals()['pending_count']);

        $page->set('ipo', (string) $this->paras->id);
        $this->assertSame(1, $page->instance()->totals()['pending_count']);
        $this->assertSame(0, $page->instance()->totals()['received_count']);

        $this->get('/admin/refunds')->assertOk();
    }

    public function test_the_ipo_dashboard_shows_refund_pending_per_ipo_and_per_account(): void
    {
        $this->app('Ved', $this->pilo, 'allotted', 13428, null, ['shares_allotted' => 12]);
        $this->app('Pratik', $this->pilo, 'not_allotted');
        $this->app('Isha', $this->pilo, 'refunded', 13428, null, ['return_amount' => 13428, 'return_date' => '2026-10-27']);

        $page = Livewire::test(IpoDashboardPage::class);
        $jio = $page->instance()->listing()->firstWhere('name', 'Jio Platforms');
        $this->assertSame([2, 1], [$jio['not_allotted'], $jio['refund_pending']], 'both states count as not allotted');
        $this->assertSame(13428.0, $jio['refund_pending_amount']);

        $page->call('openIpo', $this->jio->id);
        $account = $page->instance()->report()['accounts']->first();
        $this->assertSame([2, 1, 1], [$account['not_allotted'], $account['refund_pending'], $account['refund_received']]);

        $page->assertSee('Refund pending')->assertSee('Refund received');
    }

    public function test_those_who_did_not_get_it_are_listed_last(): void
    {
        $this->app('A Refunded', $this->pilo, 'refunded', 13428, null, ['return_amount' => 13428, 'return_date' => '2026-10-27']);
        $this->app('B NotAllotted', $this->pilo, 'not_allotted');
        $this->app('C Waiting', $this->pilo, 'applied');
        $this->app('D Allotted', $this->pilo, 'allotted', 13428, null, ['shares_allotted' => 12]);

        $source = Source::create(['name' => 'Utsav bhai', 'profit_share_percent' => 40]);
        IpoApplication::query()->update(['source_id' => $source->id]);

        $data = (new AccountOverview)->build($this->jio->id);

        $order = $data['source_groups']->first()['rows']->pluck('applicant')->all();
        $this->assertSame(['D Allotted', 'C Waiting', 'B NotAllotted', 'A Refunded'], $order);

        $share = $data['source_groups']->first()['share'];
        $this->assertSame(['D Allotted', 'C Waiting', 'B NotAllotted', 'A Refunded'], $share['focus_rows']->pluck('name')->all());
        $this->assertSame(2, $share['not_allotted']);
        $this->assertMatchesRegularExpression('/B NotAllotted[^\n]*❌ Not allotted/u', $share['text']);
        $this->assertMatchesRegularExpression('/A Refunded[^\n]*❌ Not allotted/u', $share['text']);
    }

    public function test_the_auto_allotment_check_now_leaves_the_refund_to_come(): void
    {
        $this->jio->update(['registrar' => 'bigshare', 'registrar_client_id' => '123']);
        $application = $this->app('Ved', $this->pilo, 'applied');

        $this->app->bind(BigshareChecker::class, fn () => new class implements AllotmentChecker
        {
            public function check(string $registrarClientId, string $pan): ?AllotmentCheckResult
            {
                return new AllotmentCheckResult(sharesApplied: 12, sharesAllotted: 0, refundAmount: 13333.0, refundReference: 'R1', companyName: 'Jio');
            }
        });

        app(AllotmentCheckService::class)->checkForApplication($application);

        $application->refresh();
        $this->assertSame('not_allotted', $application->status);
        $this->assertNull($application->return_amount, 'the registrar saying "refund" is not the money arriving');
        $this->assertSame(100000 - 13428.0, $this->pilo->current_balance);
    }

    public function test_the_application_form_and_list_know_the_new_status(): void
    {
        Livewire::test(CreateIpoApplication::class)
            ->fillForm([
                'ipo_id' => $this->jio->id,
                'applicant_id' => Applicant::create(['name' => 'Form Test', 'relation' => 'friend'])->id,
                'account_id' => $this->pilo->id,
                'funding_type' => 'direct',
                'amount' => 13428,
                'applied_date' => '2026-10-23',
                'status' => 'not_allotted',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, IpoApplication::where('status', 'not_allotted')->count());

        Livewire::test(ListIpoApplications::class)
            ->assertSee('Refund pending')
            ->set('activeTab', 'refund_pending')
            ->assertCountTableRecords(1);
    }

    public function test_the_dashboard_card_mentions_refund_still_to_come(): void
    {
        $this->app('Ved', $this->pilo, 'not_allotted');

        Livewire::test(OverviewStats::class)->assertSee('refund pending');
        Livewire::test(AccountBalancesTable::class)->assertSee('refund pending');
    }
}
