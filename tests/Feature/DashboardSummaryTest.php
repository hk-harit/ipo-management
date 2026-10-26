<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AccountBalancesTable;
use App\Filament\Widgets\ApplicantCommissionWidget;
use App\Filament\Widgets\OverviewStats;
use App\Filament\Widgets\PendingUdharWidget;
use App\Filament\Widgets\ProfitTrendChart;
use App\Filament\Widgets\SourceDuesWidget;
use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\Applicant;
use App\Models\Expense;
use App\Models\FundTransaction;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use App\Models\User;
use App\Services\DashboardSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Applicant $applicant;

    private Ipo $ipo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->applicant = Applicant::create(['name' => 'Friend', 'relation' => 'friend']);
        $this->ipo = Ipo::create(['name' => 'Test IPO', 'status' => 'open']);
    }

    private function apply(Account $account, float $amount, array $extra = []): IpoApplication
    {
        return IpoApplication::create(array_merge([
            'ipo_id' => $this->ipo->id,
            'applicant_id' => $this->applicant->id,
            'account_id' => $account->id,
            'amount' => $amount,
            'applied_date' => now()->toDateString(),
            'status' => 'applied',
        ], $extra));
    }

    /** A little of everything, so every term of the balance formula is exercised. */
    private function busyBooks(): array
    {
        $source = Source::create(['name' => 'Uncle', 'profit_share_percent' => 50]);
        $a = Account::create(['name' => 'A', 'type' => 'bank', 'opening_balance' => 1000]);
        $b = Account::create(['name' => 'B', 'type' => 'bank', 'opening_balance' => 200]);
        $sub = Account::create(['name' => 'Sub', 'type' => 'bank', 'opening_balance' => 0, 'parent_id' => $a->id, 'is_sub_account' => true]);

        FundTransaction::create(['source_id' => $source->id, 'account_id' => $a->id, 'type' => 'given', 'amount' => 50000, 'transaction_date' => '2026-10-01']);
        FundTransaction::create(['source_id' => $source->id, 'account_id' => $a->id, 'type' => 'returned', 'amount' => 3000, 'transaction_date' => '2026-10-02']);

        $this->apply($a, 5000); // blocked
        $this->apply($a, 4000, ['status' => 'refunded', 'return_amount' => 3905, 'return_date' => now()]); // short refund
        $this->apply($a, 3000, ['status' => 'allotted', 'shares_allotted' => 10]); // in demat
        $this->apply($a, 6000, ['status' => 'partial_allotted', 'shares_allotted' => 5, 'return_amount' => 2000]);
        $this->apply($a, 2000, ['status' => 'sold', 'return_amount' => 2600, 'return_date' => now(), 'source_id' => $source->id]);
        $this->apply($a, 1500, ['sub_account_id' => $sub->id]); // via sub-account

        Expense::create(['account_id' => $b->id, 'type' => 'expense', 'amount' => 50, 'expense_date' => '2026-10-03']);
        Expense::create(['account_id' => $b->id, 'type' => 'udhar_given', 'person_name' => 'Ramesh', 'amount' => 100, 'status' => 'pending', 'expense_date' => '2026-10-04']);
        AccountTransfer::create(['from_account_id' => $a->id, 'to_account_id' => $b->id, 'amount' => 700, 'transfer_date' => '2026-10-05']);

        return [$a, $b, $sub];
    }

    public function test_per_account_figures_match_the_account_model_exactly(): void
    {
        $accounts = $this->busyBooks();
        $figures = (new DashboardSummary)->accountFigures();

        foreach ($accounts as $account) {
            $account = $account->fresh();
            $this->assertEqualsWithDelta($account->current_balance, $figures[$account->id]['balance'], 0.001, "{$account->name} balance");
            $this->assertEqualsWithDelta($account->blocked_in_ipos, $figures[$account->id]['blocked'], 0.001, "{$account->name} blocked");
        }
    }

    public function test_totals_cover_every_active_account_and_include_demat_udhar_and_dues(): void
    {
        [$a, $b, $sub] = $this->busyBooks();
        $totals = (new DashboardSummary)->totals();

        // Sub-accounts hold real money, so they are part of the total (counted once).
        $available = $a->fresh()->current_balance + $b->fresh()->current_balance + $sub->fresh()->current_balance;

        $this->assertEqualsWithDelta($available, $totals['available'], 0.001);
        $this->assertEqualsWithDelta(6500.0, $totals['blocked'], 0.001, '5000 + 1500 applied');
        $this->assertEqualsWithDelta(7000.0, $totals['invested'], 0.001, '3000 allotted + (6000 - 2000 returned) partial');
        $this->assertEqualsWithDelta($available + 6500 + 7000, $totals['total_funds'], 0.001);
        $this->assertEqualsWithDelta(100.0, $totals['udhar_receivable'], 0.001);
        $this->assertEqualsWithDelta(47000.0, $totals['owed_to_sources'], 0.001, '50000 given - 3000 returned');
        $this->assertEqualsWithDelta(300.0, $totals['profit_owed'], 0.001, 'the sold application owes its source 300');
        $this->assertEqualsWithDelta($totals['total_funds'] + 100 - 47000 - 300, $totals['net_position'], 0.001);
    }

    public function test_profit_is_windowed_by_sale_date_and_split(): void
    {
        $source = Source::create(['name' => 'Uncle', 'profit_share_percent' => 50]);
        $account = Account::create(['name' => 'A', 'type' => 'bank', 'opening_balance' => 0]);

        $this->apply($account, 1000, ['status' => 'sold', 'return_amount' => 1400, 'return_date' => now()->subDays(2), 'source_id' => $source->id]);
        $this->apply($account, 1000, ['status' => 'sold', 'return_amount' => 1100, 'return_date' => now()->subDays(60)]);

        $summary = new DashboardSummary;

        $all = $summary->profit(...DashboardSummary::periodRange('all'));
        $this->assertSame(500.0, $all['profit']);
        $this->assertSame(2, $all['count']);

        $recent = $summary->profit(...DashboardSummary::periodRange('7d'));
        $this->assertSame(400.0, $recent['profit']);
        $this->assertSame(200.0, $recent['source_share']);
        $this->assertSame(200.0, $recent['your_share']);

        $today = $summary->profit(...DashboardSummary::periodRange('today'));
        $this->assertSame(0, $today['count']);
    }

    public function test_the_profit_series_has_one_point_per_day_or_month(): void
    {
        $account = Account::create(['name' => 'A', 'type' => 'bank', 'opening_balance' => 0]);
        $this->apply($account, 1000, ['status' => 'sold', 'return_amount' => 1400, 'return_date' => now()]);

        $summary = new DashboardSummary;

        $daily = $summary->profitSeries('7d');
        $this->assertCount(7, $daily);
        $this->assertSame(400.0, array_values($daily)[6], 'today is the last point');

        $monthly = $summary->profitSeries('all');
        $this->assertCount(12, $monthly);
        $this->assertSame(400.0, array_values($monthly)[11]);
    }

    public function test_the_dashboard_renders_every_widget_for_every_period(): void
    {
        [$a] = $this->busyBooks();

        $source = Source::first();
        $this->apply($a, 1000, ['status' => 'sold', 'return_amount' => 1400, 'return_date' => now(), 'source_id' => $source->id, 'applicant_commission_percent' => 10]);

        // Widgets lazy-load, so the page itself only needs to open; each widget is rendered below.
        $this->get('/admin')->assertOk();

        foreach (array_keys(DashboardSummary::PERIODS) as $period) {
            Livewire::test(Dashboard::class)
                ->set('filters.period', $period)
                ->assertOk();

            Livewire::test(OverviewStats::class, ['filters' => ['period' => $period]])
                ->assertOk()
                ->assertSee('Total Funds')
                ->assertSee('Net Position');
            Livewire::test(ProfitTrendChart::class, ['filters' => ['period' => $period]])->assertOk();
            Livewire::test(SourceDuesWidget::class, ['filters' => ['period' => $period]])->assertOk();
            Livewire::test(ApplicantCommissionWidget::class, ['filters' => ['period' => $period]])->assertOk();
        }

        Livewire::test(AccountBalancesTable::class)->assertOk()->assertSee('Available (ઉપલબ્ધ)');
        Livewire::test(PendingUdharWidget::class)->assertOk()->assertSee('Ramesh');
    }

    public function test_the_account_table_can_show_sub_accounts_too(): void
    {
        [, , $sub] = $this->busyBooks();

        Livewire::test(AccountBalancesTable::class)
            ->assertCanNotSeeTableRecords([$sub])
            ->filterTable('view_type', 'all')
            ->assertCanSeeTableRecords([$sub]);
    }

    public function test_a_login_only_sees_its_own_figures(): void
    {
        $this->busyBooks();
        $this->actingAs(User::factory()->create());

        $totals = (new DashboardSummary)->totals();

        $this->assertSame(0.0, $totals['total_funds']);
        $this->assertSame(0.0, $totals['owed_to_sources']);
    }
}
