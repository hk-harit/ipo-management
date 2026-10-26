<?php

namespace Tests\Feature;

use App\Filament\Pages\AccountOverviewPage;
use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\Applicant;
use App\Models\FundTransaction;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use App\Models\User;
use App\Services\AccountOverview;
use App\Services\DashboardSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccountOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Source $pravin;

    private Source $utsav;

    private Account $main;

    private Account $sub;

    private Account $pilo;

    private Ipo $jio;

    private Ipo $other;

    private Applicant $ved;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->pravin = Source::create(['name' => 'Pravin masa', 'profit_share_percent' => 50]);
        $this->utsav = Source::create(['name' => 'Utsav bhai', 'profit_share_percent' => 40]);
        $this->main = Account::create(['name' => 'Main', 'type' => 'bank', 'opening_balance' => 0]);
        $this->sub = Account::create(['name' => 'Sub', 'type' => 'bank', 'opening_balance' => 0, 'parent_id' => $this->main->id, 'is_sub_account' => true]);
        $this->pilo = Account::create(['name' => 'pilo', 'type' => 'bank', 'opening_balance' => 0]);
        $this->jio = Ipo::create(['name' => 'Jio Platforms', 'status' => 'open']);
        $this->other = Ipo::create(['name' => 'Other IPO', 'status' => 'open']);
        $this->ved = Applicant::create(['name' => 'Ved', 'relation' => 'friend', 'pan_number' => 'JQPPM7159J']);

        FundTransaction::create(['source_id' => $this->pravin->id, 'account_id' => $this->main->id, 'type' => 'given', 'amount' => 100000, 'transaction_date' => '2026-10-01']);
        FundTransaction::create(['source_id' => $this->utsav->id, 'account_id' => $this->pilo->id, 'type' => 'given', 'amount' => 50000, 'transaction_date' => '2026-10-01']);
        AccountTransfer::create(['from_account_id' => $this->main->id, 'to_account_id' => $this->sub->id, 'amount' => 30000, 'transfer_date' => '2026-10-02']);
    }

    private function apply(Account $account, Ipo $ipo, float $amount, ?Source $source = null, ?Account $sub = null): IpoApplication
    {
        return IpoApplication::create([
            'ipo_id' => $ipo->id, 'applicant_id' => $this->ved->id, 'account_id' => $account->id, 'sub_account_id' => $sub?->id,
            'source_id' => $source?->id, 'amount' => $amount, 'applied_date' => '2026-10-03', 'status' => 'applied',
        ]);
    }

    private function row(array $data, Account $account): array
    {
        return $data['accounts']->first(fn ($r) => $r['account']->id === $account->id);
    }

    public function test_each_account_shows_who_funded_it_and_what_was_applied_from_it(): void
    {
        $this->apply($this->pilo, $this->jio, 13428, $this->utsav);
        $this->apply($this->pilo, $this->jio, 13428, $this->utsav);
        $this->apply($this->pilo, $this->other, 20000, $this->pravin);
        $this->apply($this->main, $this->jio, 187992, $this->pravin);

        $data = (new AccountOverview)->build($this->jio->id);
        $pilo = $this->row($data, $this->pilo);

        $this->assertSame(50000.0, $pilo['given_total']);
        $this->assertSame('Utsav bhai', $pilo['received'][0]['source']);
        $this->assertSame(2, $pilo['focus_count'], 'only the Jio applications are in focus');
        $this->assertSame(26856.0, $pilo['focus_amount']);
        $this->assertSame(3, $pilo['all_count']);
        $this->assertSame(46856.0, $pilo['all_amount']);
        $this->assertSame(26856.0, $pilo['focus_by_source']->firstWhere('source', 'Utsav bhai')['amount']);
        $this->assertSame(50000 - 46856.0, $pilo['balance']);
    }

    public function test_balances_agree_with_the_dashboard_and_sub_account_applications_count_once(): void
    {
        $this->apply($this->main, $this->jio, 10000, $this->pravin, $this->sub);
        $this->apply($this->pilo, $this->jio, 60000, $this->utsav); // overdraws pilo

        $data = (new AccountOverview)->build(null);
        $summary = (new DashboardSummary)->totals();

        $this->assertEqualsWithDelta($summary['available'], $data['totals']['balance'], 0.001);
        $this->assertSame(1, $data['totals']['short_accounts'], 'pilo is negative');
        $this->assertSame(20000.0, $this->row($data, $this->sub)['balance']);
        $this->assertSame(70000.0, $this->row($data, $this->main)['balance']);
        $this->assertSame(1, $this->row($data, $this->sub)['focus_count'], 'the application belongs to the sub-account that paid');
        $this->assertSame(0, $this->row($data, $this->main)['focus_count']);
        $this->assertSame(30000.0, $this->row($data, $this->sub)['transfers_in']);
    }

    public function test_sub_accounts_follow_their_main_account_and_sources_are_summarised(): void
    {
        $this->apply($this->pilo, $this->jio, 13428, $this->utsav);

        $data = (new AccountOverview)->build($this->jio->id);
        $names = $data['accounts']->map(fn ($r) => $r['account']->name)->all();

        $this->assertSame(array_search('Main', $names) + 1, array_search('Sub', $names), 'Sub comes right after Main');

        $utsav = $data['sources']->first(fn ($s) => $s['source']->id === $this->utsav->id);
        $this->assertSame(50000.0, $utsav['given']);
        $this->assertSame(1, $utsav['focus_count']);
        $this->assertStringContainsString('pilo', $utsav['focus_accounts']);
    }

    public function test_the_page_opens_on_the_latest_ipo_and_can_switch(): void
    {
        $this->apply($this->pilo, $this->other, 20000, $this->pravin);
        $this->apply($this->pilo, $this->jio, 13428, $this->utsav);
        IpoApplication::query()->where('ipo_id', $this->jio->id)->update(['applied_date' => '2026-10-20']);

        $page = Livewire::test(AccountOverviewPage::class)
            ->assertOk()
            ->assertSet('ipo', (string) $this->jio->id)
            ->assertSee('Jio Platforms')
            ->assertSee('pilo')
            ->assertSee('Utsav bhai')
            ->assertSee('JQPPM7159J');

        $page->set('ipo', 'all')->assertOk()->assertSee('All IPOs');

        $this->get('/admin/account-overview')->assertOk();
    }

    public function test_a_login_only_sees_its_own_accounts(): void
    {
        $this->apply($this->pilo, $this->jio, 13428, $this->utsav);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $data = (new AccountOverview)->build(null);

        $this->assertCount(0, $data['accounts']);
        $this->assertSame(0.0, $data['totals']['given']);
    }
}
