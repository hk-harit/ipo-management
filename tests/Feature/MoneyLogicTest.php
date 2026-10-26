<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\Applicant;
use App\Models\Expense;
use App\Models\FundTransaction;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins down how money moves: account balances, refunds, sales, the profit split,
 * transfers, udhar and the passbook. These are the numbers the whole app reports,
 * so they must not change by accident.
 */
class MoneyLogicTest extends TestCase
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

    private function account(string $name = 'Bank', float $opening = 0): Account
    {
        return Account::create(['name' => $name, 'type' => 'bank', 'opening_balance' => $opening]);
    }

    private function apply(Account $account, float $amount, array $extra = []): IpoApplication
    {
        return IpoApplication::create(array_merge([
            'ipo_id' => $this->ipo->id,
            'applicant_id' => $this->applicant->id,
            'account_id' => $account->id,
            'amount' => $amount,
            'applied_date' => '2026-10-20',
            'status' => 'applied',
        ], $extra));
    }

    public function test_balance_starts_from_opening_balance_plus_funds_in(): void
    {
        $source = Source::create(['name' => 'Uncle', 'profit_share_percent' => 50]);
        $account = $this->account('Bank', 1000);

        FundTransaction::create(['source_id' => $source->id, 'account_id' => $account->id, 'type' => 'given', 'amount' => 5000, 'transaction_date' => '2026-10-01']);
        FundTransaction::create(['source_id' => $source->id, 'account_id' => $account->id, 'type' => 'returned', 'amount' => 800, 'transaction_date' => '2026-10-02']);

        $this->assertSame(5200.0, $account->current_balance);
        $this->assertSame(4200.0, $source->balance_due);
    }

    public function test_applying_blocks_money_and_total_balance_stays_the_same(): void
    {
        $account = $this->account('Bank', 10000);
        $this->apply($account, 3000);

        $this->assertSame(7000.0, $account->current_balance);
        $this->assertSame(3000.0, $account->blocked_in_ipos);
        $this->assertSame(10000.0, $account->total_balance);
    }

    public function test_refund_credits_the_account_back_and_unblocks(): void
    {
        $account = $this->account('Bank', 10000);
        $app = $this->apply($account, 3000);

        $app->update(['status' => 'refunded']);
        $app->refresh();

        $this->assertSame('3000.00', (string) $app->return_amount, 'a refund with no amount defaults to the full application');
        $this->assertNotNull($app->return_date);
        $this->assertSame(10000.0, $account->current_balance);
        $this->assertSame(0.0, $account->blocked_in_ipos);
    }

    public function test_a_short_refund_credits_only_what_came_back(): void
    {
        $account = $this->account('Bank', 10000);
        $app = $this->apply($account, 3000);

        $app->update(['status' => 'refunded', 'return_amount' => 2905, 'return_date' => '2026-10-22']);

        $this->assertSame(9905.0, $account->current_balance);
    }

    public function test_allotted_shares_leave_the_account_until_sold(): void
    {
        $account = $this->account('Bank', 10000);
        $app = $this->apply($account, 3000);

        $app->update(['status' => 'allotted', 'shares_allotted' => 10]);

        $this->assertSame(7000.0, $account->current_balance);
        $this->assertSame(0.0, $account->blocked_in_ipos);
        $this->assertSame(3000.0, $account->invested_in_ipos);
    }

    public function test_sale_credits_proceeds_and_reports_profit(): void
    {
        $account = $this->account('Bank', 10000);
        $app = $this->apply($account, 3000);

        $app->update([
            'status' => 'sold',
            'shares_allotted' => 10,
            'shares_sold' => 10,
            'sale_price_per_share' => 400,
            'return_amount' => 4000,
            'return_date' => '2026-10-30',
        ]);
        $app->refresh();

        $this->assertSame(11000.0, $account->current_balance);
        $this->assertSame(1000.0, $app->profit);
    }

    public function test_profit_is_split_between_source_applicant_and_you(): void
    {
        $source = Source::create(['name' => 'Uncle', 'profit_share_percent' => 50]);
        $account = $this->account();

        $app = $this->apply($account, 3000, [
            'source_id' => $source->id,
            'status' => 'sold',
            'return_amount' => 4000,
            'applicant_commission_percent' => 10,
        ]);

        $this->assertSame(1000.0, $app->profit);
        $this->assertSame(500.0, $app->source_profit_share);
        $this->assertSame(100.0, $app->applicant_profit_share);
        $this->assertSame(400.0, $app->your_profit_share);
        $this->assertSame(500.0, $source->total_profit_share_due);
    }

    public function test_a_per_application_percent_overrides_the_source_default(): void
    {
        $source = Source::create(['name' => 'Uncle', 'profit_share_percent' => 50]);
        $app = $this->apply($this->account(), 3000, [
            'source_id' => $source->id,
            'source_share_percent_override' => 30,
            'status' => 'sold',
            'return_amount' => 4000,
        ]);

        $this->assertSame(300.0, $app->source_profit_share);
        $this->assertSame(700.0, $app->your_profit_share);
    }

    public function test_a_loss_is_split_the_same_way(): void
    {
        $source = Source::create(['name' => 'Uncle', 'profit_share_percent' => 50]);
        $app = $this->apply($this->account(), 3000, [
            'source_id' => $source->id,
            'status' => 'sold',
            'return_amount' => 2000,
        ]);

        $this->assertSame(-1000.0, $app->profit);
        $this->assertSame(-500.0, $app->source_profit_share);
    }

    public function test_profit_is_unknown_until_sold(): void
    {
        $app = $this->apply($this->account(), 3000);

        $this->assertNull($app->profit);
        $this->assertNull($app->your_profit_share);
    }

    public function test_transfers_move_money_between_accounts(): void
    {
        $a = $this->account('A', 5000);
        $b = $this->account('B', 0);

        AccountTransfer::create(['from_account_id' => $a->id, 'to_account_id' => $b->id, 'amount' => 2000, 'transfer_date' => '2026-10-10']);

        $this->assertSame(3000.0, $a->current_balance);
        $this->assertSame(2000.0, $b->current_balance);
    }

    public function test_applying_from_another_account_creates_and_tracks_an_auto_transfer(): void
    {
        $paying = $this->account('Paying', 0);
        $funder = $this->account('Funder', 10000);

        $app = $this->apply($paying, 3000, ['funding_type' => 'transferred', 'funding_account_id' => $funder->id]);
        $app->refresh();

        $this->assertNotNull($app->auto_transfer_id);
        $this->assertSame(7000.0, $funder->current_balance);
        $this->assertSame(0.0, $paying->current_balance, 'transfer in, application out');

        $app->update(['amount' => 4000]);
        $this->assertSame(6000.0, $funder->current_balance, 'the auto transfer follows the amount');

        $app->delete();
        $this->assertSame(0, AccountTransfer::count(), 'deleting the application removes its transfer');
        $this->assertSame(10000.0, $funder->current_balance);
    }

    public function test_switching_back_to_direct_funding_removes_the_auto_transfer(): void
    {
        $paying = $this->account('Paying', 5000);
        $funder = $this->account('Funder', 10000);
        $app = $this->apply($paying, 3000, ['funding_type' => 'transferred', 'funding_account_id' => $funder->id]);

        $app->update(['funding_type' => 'direct']);

        $this->assertSame(0, AccountTransfer::count());
        $this->assertSame(10000.0, $funder->current_balance);
    }

    public function test_udhar_given_and_received_move_the_balance(): void
    {
        $account = $this->account('Bank', 10000);

        Expense::create(['account_id' => $account->id, 'type' => 'expense', 'category' => 'Brokerage', 'amount' => 100, 'expense_date' => '2026-10-01']);
        Expense::create(['account_id' => $account->id, 'type' => 'udhar_given', 'person_name' => 'Ramesh', 'amount' => 900, 'expense_date' => '2026-10-02', 'status' => 'pending']);
        $this->assertSame(9000.0, $account->current_balance);

        Expense::create(['account_id' => $account->id, 'type' => 'udhar_received', 'person_name' => 'Ramesh', 'amount' => 900, 'expense_date' => '2026-10-05', 'status' => 'completed']);
        $this->assertSame(9900.0, $account->current_balance);
    }

    public function test_the_passbook_ends_on_the_current_balance(): void
    {
        $source = Source::create(['name' => 'Uncle', 'profit_share_percent' => 50]);
        $a = $this->account('A', 1000);
        $b = $this->account('B', 0);

        FundTransaction::create(['source_id' => $source->id, 'account_id' => $a->id, 'type' => 'given', 'amount' => 20000, 'transaction_date' => '2026-10-01']);
        $app = $this->apply($a, 5000);
        $app->update(['status' => 'refunded', 'return_amount' => 4905, 'return_date' => '2026-10-22']);
        $this->apply($a, 3000, ['status' => 'sold', 'return_amount' => 3500, 'return_date' => '2026-10-25']);
        Expense::create(['account_id' => $a->id, 'type' => 'expense', 'amount' => 50, 'expense_date' => '2026-10-26']);
        AccountTransfer::create(['from_account_id' => $a->id, 'to_account_id' => $b->id, 'amount' => 1000, 'transfer_date' => '2026-10-27']);

        foreach ([$a, $b] as $account) {
            $ledger = $account->buildLedger();
            $this->assertEqualsWithDelta($account->current_balance, (float) $ledger->last()['balance'], 0.001, "{$account->name} passbook must agree with its balance");
        }
    }

    public function test_a_sub_account_is_its_own_bucket_debited_alone(): void
    {
        $main = $this->account('Main', 10000);
        $sub = Account::create(['name' => 'Sub', 'type' => 'bank', 'opening_balance' => 0, 'parent_id' => $main->id, 'is_sub_account' => true]);

        $this->apply($main, 3000, ['sub_account_id' => $sub->id]);

        $this->assertSame(10000.0, $main->fresh()->current_balance, 'the main account is not debited for a sub-account application');
        $this->assertSame(-3000.0, $sub->fresh()->current_balance, 'the sub-account pays (and shows overdrawn until funded)');
        $this->assertCount(1, Account::mainAccounts()->get());
        $this->assertSame(7000.0, (float) Account::query()->get()->sum('current_balance'), 'across all accounts the 3000 is counted once');
    }

    public function test_each_login_only_sees_its_own_money(): void
    {
        $this->account('Mine', 100);
        $other = User::factory()->create();

        $this->actingAs($other);

        $this->assertSame(0, Account::count());
    }

    public function test_a_short_refund_reports_the_shortfall(): void
    {
        $account = $this->account('Bank', 10000);
        $app = $this->apply($account, 15000);
        $this->assertNull($app->refund_shortfall);

        $app->update(['status' => 'refunded', 'return_amount' => 14905, 'return_date' => '2026-10-22']);
        $this->assertSame(95.0, $app->refresh()->refund_shortfall);

        $full = $this->apply($account, 1000);
        $full->update(['status' => 'refunded']);
        $this->assertNull($full->refresh()->refund_shortfall, 'a full refund has no shortfall');
    }
}
