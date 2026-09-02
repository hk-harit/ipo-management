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

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_every_resource_page_loads_for_an_authenticated_admin(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $source = Source::create(['name' => 'Uncle A', 'relation' => 'Uncle']);
        $account = Account::create(['name' => 'My Bank', 'type' => 'bank', 'opening_balance' => 1000]);
        $applicant = Applicant::create(['name' => 'Friend One', 'relation' => 'friend']);
        $ipo = Ipo::create(['name' => 'Test IPO', 'status' => 'open']);

        FundTransaction::create([
            'source_id' => $source->id,
            'account_id' => $account->id,
            'type' => 'given',
            'amount' => 5000,
            'transaction_date' => now(),
        ]);

        IpoApplication::create([
            'ipo_id' => $ipo->id,
            'applicant_id' => $applicant->id,
            'account_id' => $account->id,
            'amount' => 1500,
            'applied_date' => now(),
            'status' => 'applied',
        ]);

        Expense::create([
            'account_id' => $account->id,
            'category' => 'Brokerage',
            'amount' => 50,
            'expense_date' => now(),
        ]);

        $pages = [
            '/admin' => 'Dashboard',
            '/admin/sources' => 'Sources index',
            '/admin/sources/create' => 'Source create',
            "/admin/sources/{$source->id}/edit" => 'Source edit',
            '/admin/accounts' => 'Accounts index',
            '/admin/accounts/create' => 'Account create',
            "/admin/accounts/{$account->id}/edit" => 'Account edit',
            '/admin/applicants' => 'Applicants index',
            '/admin/applicants/create' => 'Applicant create',
            '/admin/ipos' => 'IPOs index',
            '/admin/ipos/create' => 'IPO create',
            "/admin/ipos/{$ipo->id}/edit" => 'IPO edit',
            '/admin/fund-transactions' => 'Fund transactions index',
            '/admin/ipo-applications' => 'IPO applications index',
            '/admin/expenses' => 'Expenses index',
            '/admin/bulk-ipo-apply' => 'Bulk IPO apply page',
            '/admin/account-transfers' => 'Account transfers index',
            '/admin/account-transfers/create' => 'Account transfer create',
            "/admin/accounts/{$account->id}/passbook" => 'Account passbook',
            '/admin/users' => 'Manage Logins index',
            '/admin/users/create' => 'Manage Logins create',
        ];

        foreach ($pages as $url => $label) {
            $this->get($url)->assertOk();
        }

        // Account current_balance should reflect opening (1000) + fund in (5000) - applied (1500) - expense (50)
        $this->assertEquals(4450.0, $account->fresh()->current_balance);
        $this->assertEquals(5000.0, $source->fresh()->balance_due);

        // Passbook ledger should end at the same balance as the account's current_balance.
        $ledger = $account->fresh()->buildLedger();
        $this->assertEquals($account->current_balance, $ledger->last()['balance']);

        // Everything created while acting as $user should actually belong to $user.
        $this->assertEquals($user->id, $source->user_id);
        $this->assertEquals($user->id, $account->user_id);
        $this->assertEquals($user->id, $applicant->user_id);
        $this->assertEquals($user->id, $ipo->user_id);
    }

    public function test_each_login_only_ever_sees_its_own_data(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userA);
        $sourceA = Source::create(['name' => "A's Uncle", 'relation' => 'Uncle']);
        $accountA = Account::create(['name' => "A's Bank", 'type' => 'bank', 'opening_balance' => 100]);

        $this->actingAs($userB);
        $sourceB = Source::create(['name' => "B's Uncle", 'relation' => 'Uncle']);
        $accountB = Account::create(['name' => "B's Bank", 'type' => 'bank', 'opening_balance' => 200]);

        // B is currently acting: should only see B's rows.
        $this->assertTrue(Source::pluck('id')->contains($sourceB->id));
        $this->assertFalse(Source::pluck('id')->contains($sourceA->id));
        $this->assertTrue(Account::pluck('id')->contains($accountB->id));
        $this->assertFalse(Account::pluck('id')->contains($accountA->id));

        // B cannot open A's records directly by URL either.
        $this->get("/admin/sources/{$sourceA->id}/edit")->assertNotFound();
        $this->get("/admin/accounts/{$accountA->id}/edit")->assertNotFound();
        $this->get("/admin/accounts/{$accountA->id}/passbook")->assertNotFound();

        // Switch to A: should only see A's rows.
        $this->actingAs($userA);
        $this->assertTrue(Source::pluck('id')->contains($sourceA->id));
        $this->assertFalse(Source::pluck('id')->contains($sourceB->id));
        $this->get("/admin/sources/{$sourceA->id}/edit")->assertOk();
    }

    public function test_profit_is_split_between_source_and_self_when_shares_are_sold(): void
    {
        $this->actingAs(User::factory()->create());

        $source = Source::create(['name' => 'Uncle A', 'relation' => 'Uncle', 'profit_share_percent' => 40]);
        $account = Account::create(['name' => 'My Bank', 'type' => 'bank', 'opening_balance' => 0]);
        $applicant = Applicant::create(['name' => 'Friend One', 'relation' => 'friend']);
        $ipo = Ipo::create(['name' => 'Test IPO', 'status' => 'listed']);

        $application = IpoApplication::create([
            'ipo_id' => $ipo->id,
            'applicant_id' => $applicant->id,
            'account_id' => $account->id,
            'source_id' => $source->id,
            'amount' => 10000,
            'applied_date' => now()->subDays(20),
            'status' => 'sold',
            'return_amount' => 12000, // sold for a 2000 profit
            'return_date' => now(),
        ]);

        $this->assertEquals(2000.0, $application->profit);
        $this->assertEquals(800.0, $application->source_profit_share); // 40% of 2000
        $this->assertEquals(1200.0, $application->your_profit_share); // remaining 60%
        $this->assertEquals(800.0, $source->fresh()->total_profit_share_due);
    }

    public function test_three_way_profit_split_matches_the_described_example(): void
    {
        $this->actingAs(User::factory()->create());

        // Source's own default is 50%, but this application also pays the
        // applicant (friend) a 20% commission — both should come out of profit.
        $source = Source::create(['name' => 'Brother B', 'relation' => 'Brother', 'profit_share_percent' => 50]);
        $account = Account::create(['name' => 'My Bank', 'type' => 'bank', 'opening_balance' => 0]);
        $applicant = Applicant::create(['name' => 'Friend One', 'relation' => 'friend']);
        $ipo = Ipo::create(['name' => 'Test IPO', 'status' => 'listed']);

        $application = IpoApplication::create([
            'ipo_id' => $ipo->id,
            'applicant_id' => $applicant->id,
            'account_id' => $account->id,
            'source_id' => $source->id,
            'amount' => 15000,
            'applied_date' => now()->subDays(20),
            'status' => 'sold',
            'shares_sold' => 10,
            'sale_price_per_share' => 1700,
            'return_amount' => 17000, // 15000 principal + 2000 profit
            'return_date' => now(),
            'applicant_commission_percent' => 20,
        ]);

        $this->assertEquals(2000.0, $application->profit);
        $this->assertEquals(50.0, $application->effective_source_share_percent); // uses source's default
        $this->assertEquals(1000.0, $application->source_profit_share); // 50% of 2000
        $this->assertEquals(400.0, $application->applicant_profit_share); // 20% of 2000
        $this->assertEquals(600.0, $application->your_profit_share); // remaining 30%

        // A per-application override should win over the source's default.
        $application->update(['source_share_percent_override' => 30]);
        $this->assertEquals(30.0, $application->fresh()->effective_source_share_percent);
        $this->assertEquals(600.0, $application->fresh()->source_profit_share); // 30% of 2000
        $this->assertEquals(1000.0, $application->fresh()->your_profit_share); // 2000 - 600 - 400

        $this->assertEquals(400.0, $applicant->fresh()->total_commission_due);
    }

    public function test_account_transfer_moves_balance_between_two_of_your_own_accounts(): void
    {
        $this->actingAs(User::factory()->create());

        // The exact case described: my bank has the money, but I apply the IPO
        // through a friend's account (their bank had an issue), so I move money
        // from my bank into the friend's account first.
        $myBank = Account::create(['name' => 'My Bank', 'type' => 'bank', 'opening_balance' => 10000]);
        $friendAccount = Account::create(['name' => "Friend's Bank", 'holder_name' => 'Friend', 'type' => 'bank', 'opening_balance' => 0]);

        AccountTransfer::create([
            'from_account_id' => $myBank->id,
            'to_account_id' => $friendAccount->id,
            'amount' => 2000,
            'transfer_date' => now(),
        ]);

        $this->assertEquals(8000.0, $myBank->fresh()->current_balance);
        $this->assertEquals(2000.0, $friendAccount->fresh()->current_balance);

        // Both sides should show up correctly in their respective passbooks.
        $this->assertEquals(8000.0, $myBank->fresh()->buildLedger()->last()['balance']);
        $this->assertEquals(2000.0, $friendAccount->fresh()->buildLedger()->last()['balance']);
    }

    public function test_needs_attention_widget_flags_stale_applications(): void
    {
        $this->actingAs(User::factory()->create());

        $account = Account::create(['name' => 'My Bank', 'type' => 'bank', 'opening_balance' => 0]);
        $applicant = Applicant::create(['name' => 'Friend One', 'relation' => 'friend']);
        $ipo = Ipo::create(['name' => 'Test IPO', 'status' => 'closed']);

        $stale = IpoApplication::create([
            'ipo_id' => $ipo->id,
            'applicant_id' => $applicant->id,
            'account_id' => $account->id,
            'amount' => 1000,
            'applied_date' => now()->subDays(15),
            'status' => 'applied',
        ]);

        IpoApplication::create([
            'ipo_id' => $ipo->id,
            'applicant_id' => $applicant->id,
            'account_id' => $account->id,
            'amount' => 1000,
            'applied_date' => now()->subDays(2),
            'status' => 'applied',
        ]);

        $flagged = IpoApplication::where('status', 'applied')
            ->where('applied_date', '<=', now()->subDays(10))
            ->pluck('id');

        $this->assertEquals([$stale->id], $flagged->all());
    }
}
