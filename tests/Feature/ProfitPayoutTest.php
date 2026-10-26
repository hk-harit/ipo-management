<?php

namespace Tests\Feature;

use App\Filament\Resources\ProfitPayoutResource\Pages\CreateProfitPayout;
use App\Filament\Source\Pages\MyLedger;
use App\Filament\Source\Widgets\PortalStats;
use App\Filament\Widgets\ApplicantCommissionWidget;
use App\Filament\Widgets\SourceDuesWidget;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\ProfitPayout;
use App\Models\Source;
use App\Models\User;
use App\Services\DashboardSummary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ProfitPayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Account $account;

    private Applicant $applicant;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);

        $this->account = Account::create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 10000]);
        $this->applicant = Applicant::create(['name' => 'Friend', 'relation' => 'friend']);
        $this->source = Source::create(['name' => 'Uncle', 'profit_share_percent' => 50]);

        // Sold for a profit of 1000: source 500 (50%), applicant 100 (10%), you 400.
        IpoApplication::create([
            'ipo_id' => Ipo::create(['name' => 'Test IPO', 'status' => 'listed'])->id,
            'applicant_id' => $this->applicant->id,
            'account_id' => $this->account->id,
            'source_id' => $this->source->id,
            'amount' => 3000,
            'applied_date' => '2026-10-20',
            'status' => 'sold',
            'return_amount' => 4000,
            'return_date' => '2026-10-30',
            'applicant_commission_percent' => 10,
        ]);
    }

    private function pay(array $to, float $amount, string $date = '2026-11-01'): ProfitPayout
    {
        return ProfitPayout::create(array_merge([
            'account_id' => $this->account->id,
            'amount' => $amount,
            'payout_date' => $date,
        ], $to));
    }

    public function test_pending_is_earned_minus_paid_for_sources_and_applicants(): void
    {
        $this->assertSame(500.0, $this->source->profit_share_pending);
        $this->assertSame(100.0, $this->applicant->commission_pending);

        $this->pay(['source_id' => $this->source->id], 200);
        $this->pay(['applicant_id' => $this->applicant->id], 100);

        $this->assertSame(200.0, $this->source->fresh()->profit_share_paid);
        $this->assertSame(300.0, $this->source->fresh()->profit_share_pending);
        $this->assertSame(0.0, $this->applicant->fresh()->commission_pending);
        $this->assertSame(1300.0, $this->source->fresh()->total_payable - $this->source->fresh()->balance_due + 1000, 'total payable = principal due + pending');
    }

    public function test_a_payout_leaves_the_chosen_account_and_shows_in_the_passbook(): void
    {
        $before = $this->account->current_balance; // 10000 - 3000 + 4000

        $this->pay(['source_id' => $this->source->id], 500);

        $this->assertSame($before - 500, $this->account->current_balance);

        $ledger = $this->account->buildLedger();
        $this->assertTrue($ledger->contains(fn ($e) => $e['description'] === 'Profit share paid to Uncle' && $e['debit'] === 500.0));
        $this->assertEqualsWithDelta($this->account->current_balance, (float) $ledger->last()['balance'], 0.001);
    }

    public function test_a_payout_needs_exactly_one_payee(): void
    {
        foreach ([[], ['source_id' => $this->source->id, 'applicant_id' => $this->applicant->id]] as $payee) {
            try {
                $this->pay($payee, 100);
                $this->fail('a payout with no payee, or two payees, must be refused');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('source_id', $e->errors());
            }
        }

        $this->assertSame(0, ProfitPayout::count());
    }

    public function test_payees_and_accounts_with_payouts_cannot_be_deleted(): void
    {
        $this->pay(['source_id' => $this->source->id], 100);
        $this->pay(['applicant_id' => $this->applicant->id], 50);

        $this->assertFalse($this->source->delete());
        $this->assertFalse($this->applicant->delete());
        $this->assertFalse($this->account->delete());
        $this->assertSame(2, ProfitPayout::count());
    }

    public function test_the_database_itself_refuses_to_drop_a_paid_payee(): void
    {
        $this->pay(['source_id' => $this->source->id], 100);

        $this->expectException(QueryException::class);
        \DB::table('sources')->where('id', $this->source->id)->delete();
    }

    public function test_dashboard_figures_follow_payouts_and_count_unpaid_profit_as_owed(): void
    {
        $summary = new DashboardSummary;
        $totals = $summary->totals();

        $this->assertEqualsWithDelta(600.0, $totals['profit_owed'], 0.001, 'source 500 + applicant 100 not yet paid');
        $this->assertEqualsWithDelta($totals['total_funds'] - 600, $totals['net_position'], 0.001);

        $this->pay(['source_id' => $this->source->id], 500);
        $after = (new DashboardSummary)->totals();

        $this->assertEqualsWithDelta(100.0, $after['profit_owed'], 0.001);
        $this->assertEqualsWithDelta($this->account->fresh()->current_balance, $after['available'], 0.001, 'dashboard agrees with the account');
    }

    public function test_recording_a_payout_through_the_panel_and_warning_on_an_advance(): void
    {
        Livewire::test(CreateProfitPayout::class)
            ->fillForm([
                'payee_type' => 'source',
                'source_id' => $this->source->id,
                'account_id' => $this->account->id,
                'amount' => 500,
                'payout_date' => '2026-11-01',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0.0, $this->source->fresh()->profit_share_pending);

        Livewire::test(CreateProfitPayout::class)
            ->fillForm([
                'payee_type' => 'source',
                'source_id' => $this->source->id,
                'account_id' => $this->account->id,
                'amount' => 50,
                'payout_date' => '2026-11-02',
            ])
            ->call('create')
            ->assertNotified('Paid more than was pending');

        Livewire::test(CreateProfitPayout::class)
            ->fillForm(['payee_type' => 'source', 'source_id' => $this->source->id, 'account_id' => $this->account->id, 'amount' => 0, 'payout_date' => '2026-11-02'])
            ->call('create')
            ->assertHasFormErrors(['amount']);
    }

    public function test_dashboard_widgets_show_paid_and_pending(): void
    {
        $this->pay(['source_id' => $this->source->id], 200);

        Livewire::test(SourceDuesWidget::class)
            ->assertOk()
            ->assertSee('Profit Pending')
            ->assertSee('Pay Profit');

        Livewire::test(ApplicantCommissionWidget::class)->assertOk()->assertSee('Pending');
    }

    public function test_a_source_sees_only_its_own_payouts_in_the_portal(): void
    {
        $other = Source::create(['name' => 'Other', 'profit_share_percent' => 50]);
        $this->pay(['source_id' => $this->source->id], 200, '2026-11-01');
        $this->pay(['source_id' => $other->id], 999, '2026-11-02');

        $this->source->forceFill(['portal_enabled' => true, 'password' => 'secret123', 'mobile' => '9876543210'])->save();
        $this->actingAs($this->source, 'source');

        $payouts = Livewire::test(MyLedger::class)->assertOk()->instance()->payouts();

        $this->assertCount(1, $payouts);
        $this->assertSame('200.00', (string) $payouts->first()->amount);

        Livewire::test(PortalStats::class)->assertSee('Paid ₹200.00')->assertSee('Pending ₹300.00');
    }
}
