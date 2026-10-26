<?php

namespace Tests\Feature;

use App\Filament\Resources\IpoApplicationResource\Pages\CreateIpoApplication;
use App\Filament\Widgets\AccountBalancesTable;
use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\Applicant;
use App\Models\FundTransaction;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use App\Models\User;
use App\Services\DashboardSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The real working flow: a source's money lands in a MAIN account, is moved into a
 * SUB-account, IPOs are applied from the sub-account, the sub-account is cleared
 * back into the main account, and the main account pays the source back.
 * A sub-account holds real money, so it is debited alone — never the main account too.
 */
class SubAccountFlowTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    private Account $main;

    private Account $sub;

    private Applicant $applicant;

    private Ipo $ipo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->source = Source::create(['name' => 'Uncle', 'profit_share_percent' => 50]);
        $this->main = Account::create(['name' => 'Main', 'type' => 'bank', 'opening_balance' => 0]);
        $this->sub = Account::create(['name' => 'Sub', 'type' => 'bank', 'opening_balance' => 0, 'parent_id' => $this->main->id, 'is_sub_account' => true]);
        $this->applicant = Applicant::create(['name' => 'Friend', 'relation' => 'friend']);
        $this->ipo = Ipo::create(['name' => 'Test IPO', 'status' => 'open']);
    }

    private function balance(Account $account): float
    {
        return round($account->fresh()->current_balance, 2);
    }

    private function giveToMain(float $amount): void
    {
        FundTransaction::create(['source_id' => $this->source->id, 'account_id' => $this->main->id, 'type' => 'given', 'amount' => $amount, 'transaction_date' => '2026-10-01']);
    }

    private function moveMainToSub(float $amount): AccountTransfer
    {
        return AccountTransfer::create(['from_account_id' => $this->main->id, 'to_account_id' => $this->sub->id, 'amount' => $amount, 'transfer_date' => '2026-10-02']);
    }

    private function applyFromSub(float $amount): IpoApplication
    {
        return IpoApplication::create([
            'ipo_id' => $this->ipo->id,
            'applicant_id' => $this->applicant->id,
            'account_id' => $this->main->id,
            'sub_account_id' => $this->sub->id,
            'amount' => $amount,
            'applied_date' => '2026-10-03',
            'status' => 'applied',
        ]);
    }

    public function test_an_application_from_a_sub_account_debits_only_the_sub_account(): void
    {
        $this->giveToMain(100000);
        $this->moveMainToSub(60000);
        $this->applyFromSub(13428);

        $this->assertSame(40000.0, $this->balance($this->main), 'the main account is not touched by a sub-account application');
        $this->assertSame(46572.0, $this->balance($this->sub));
        $this->assertSame(13428.0, $this->sub->fresh()->blocked_in_ipos);
        $this->assertSame(0.0, $this->main->fresh()->blocked_in_ipos);
    }

    public function test_the_whole_cycle_ends_with_every_rupee_back_with_the_source(): void
    {
        $this->giveToMain(100000);
        $this->moveMainToSub(60000);
        $app = $this->applyFromSub(13428);

        $app->update(['status' => 'refunded']);
        $this->assertSame(60000.0, $this->balance($this->sub), 'refund returns to the sub-account');

        AccountTransfer::create(['from_account_id' => $this->sub->id, 'to_account_id' => $this->main->id, 'amount' => 60000, 'transfer_date' => '2026-10-10']);
        $this->assertSame(0.0, $this->balance($this->sub));
        $this->assertSame(100000.0, $this->balance($this->main));

        FundTransaction::create(['source_id' => $this->source->id, 'account_id' => $this->main->id, 'type' => 'returned', 'amount' => 100000, 'transaction_date' => '2026-10-11']);
        $this->assertSame(0.0, $this->balance($this->main));
        $this->assertSame(0.0, $this->source->fresh()->balance_due);
    }

    public function test_dashboard_totals_count_money_in_sub_accounts_once(): void
    {
        $this->giveToMain(100000);
        $this->moveMainToSub(60000);
        $this->applyFromSub(13428);

        $totals = (new DashboardSummary)->totals();

        $this->assertEqualsWithDelta(86572.0, $totals['available'], 0.001, 'main 40,000 + sub 46,572');
        $this->assertEqualsWithDelta(13428.0, $totals['blocked'], 0.001);
        $this->assertEqualsWithDelta(100000.0, $totals['total_funds'], 0.001, 'nothing vanished or doubled: equals what the source gave');
    }

    public function test_per_account_figures_still_match_the_account_model_and_roll_up(): void
    {
        $this->giveToMain(100000);
        $this->moveMainToSub(60000);
        $app = $this->applyFromSub(13428);
        $allotted = IpoApplication::create([
            'ipo_id' => $this->ipo->id, 'applicant_id' => $this->applicant->id, 'account_id' => $this->main->id, 'sub_account_id' => $this->sub->id,
            'amount' => 5000, 'applied_date' => '2026-10-03', 'status' => 'allotted', 'shares_allotted' => 5,
        ]);

        $summary = new DashboardSummary;
        $figures = $summary->accountFigures();

        foreach ([$this->main, $this->sub] as $account) {
            $this->assertEqualsWithDelta($this->balance($account), $figures[$account->id]['balance'], 0.001, "{$account->name} balance");
            $this->assertEqualsWithDelta($account->fresh()->blocked_in_ipos, $figures[$account->id]['blocked'], 0.001, "{$account->name} blocked");
        }

        $rollup = $summary->rollup($this->main->id);
        $this->assertEqualsWithDelta(40000 + (60000 - 13428 - 5000), $rollup['balance'], 0.001);
        $this->assertEqualsWithDelta(13428.0, $rollup['blocked'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $rollup['invested'], 0.001);
        $this->assertEqualsWithDelta(60000 - 13428 - 5000, $rollup['in_sub_accounts'], 0.001, 'cash still to bring back from sub-accounts');
        $this->assertNotNull($app);
        $this->assertNotNull($allotted);
    }

    public function test_funding_a_sub_account_application_from_another_account_tops_up_the_sub_account(): void
    {
        $funder = Account::create(['name' => 'Funder', 'type' => 'bank', 'opening_balance' => 20000]);

        IpoApplication::create([
            'ipo_id' => $this->ipo->id, 'applicant_id' => $this->applicant->id, 'account_id' => $this->main->id, 'sub_account_id' => $this->sub->id,
            'funding_type' => 'transferred', 'funding_account_id' => $funder->id,
            'amount' => 13428, 'applied_date' => '2026-10-03', 'status' => 'applied',
        ]);

        $this->assertSame(6572.0, $this->balance($funder));
        $this->assertSame(0.0, $this->balance($this->sub), 'topped up by the transfer, then debited by the application');
        $this->assertSame(0.0, $this->balance($this->main));
    }

    public function test_a_plain_application_on_a_main_account_is_unchanged(): void
    {
        $this->giveToMain(50000);

        IpoApplication::create([
            'ipo_id' => $this->ipo->id, 'applicant_id' => $this->applicant->id, 'account_id' => $this->main->id,
            'amount' => 13428, 'applied_date' => '2026-10-03', 'status' => 'applied',
        ]);

        $this->assertSame(36572.0, $this->balance($this->main));
        $this->assertSame(13428.0, $this->main->fresh()->blocked_in_ipos);
    }

    public function test_the_overdraft_warning_checks_the_sub_account_that_pays(): void
    {
        $this->giveToMain(100000); // plenty in the main account, nothing in the sub-account

        Livewire::test(CreateIpoApplication::class)
            ->fillForm([
                'ipo_id' => $this->ipo->id,
                'applicant_id' => $this->applicant->id,
                'account_id' => $this->main->id,
                'sub_account_id' => $this->sub->id,
                'funding_type' => 'direct',
                'amount' => 13428,
                'applied_date' => '2026-10-03',
                'status' => 'applied',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Account is overdrawn');
    }

    public function test_the_dashboard_account_table_lists_cash_in_sub_accounts_on_the_main_row(): void
    {
        $this->giveToMain(100000);
        $this->moveMainToSub(60000);

        Livewire::test(AccountBalancesTable::class)
            ->assertOk()
            ->assertSee('In Sub-accounts')
            ->assertSee('₹60,000.00')
            ->assertSee('₹40,000.00');
    }
}
