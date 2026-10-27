<?php

namespace Tests\Feature;

use App\Filament\Pages\IpoDashboardPage;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\FundTransaction;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IpoDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Ipo $jio;

    private Ipo $paras;

    private Account $pilo;

    private Account $om;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->jio = Ipo::create(['name' => 'Jio Platforms', 'status' => 'closed', 'issue_type' => 'EQ', 'min_amount' => 13428, 'lot_size' => 12, 'price_min' => 1065, 'price_max' => 1119]);
        $this->paras = Ipo::create(['name' => 'Paras Healthcare', 'status' => 'listed', 'min_amount' => 14553, 'lot_size' => 21]);
        $pravin = Source::create(['name' => 'Pravin masa', 'profit_share_percent' => 50]);
        $utsav = Source::create(['name' => 'Utsav bhai', 'profit_share_percent' => 40]);
        $this->pilo = Account::create(['name' => 'pilo', 'type' => 'bank', 'opening_balance' => 0]);
        $this->om = Account::create(['name' => 'Om Indus', 'type' => 'bank', 'opening_balance' => 0]);
        FundTransaction::create(['source_id' => $pravin->id, 'account_id' => $this->om->id, 'type' => 'given', 'amount' => 500000, 'transaction_date' => '2026-10-01']);

        $make = fn (Ipo $ipo, string $name, string $pan, Account $acc, Source $src, float $amount, string $status, ?int $shares, string $date) => IpoApplication::create([
            'ipo_id' => $ipo->id,
            'applicant_id' => Applicant::create(['name' => $name, 'relation' => 'friend', 'pan_number' => $pan])->id,
            'account_id' => $acc->id, 'source_id' => $src->id, 'amount' => $amount,
            'applied_date' => $date, 'status' => $status, 'shares_allotted' => $shares,
            'applicant_commission_percent' => $src->name === 'Utsav bhai' ? 25 : 0,
        ]);

        // Jio: 2 allotted in pilo, 1 not allotted in pilo, 1 allotted + 1 waiting in Om Indus
        $make($this->jio, 'Ved Mordiya', 'JQPPM7159J', $this->pilo, $utsav, 13428, 'allotted', 12, '2026-10-23');
        $make($this->jio, 'Pratik Kanadiya', 'OYSPK2271R', $this->pilo, $utsav, 13428, 'allotted', 12, '2026-10-23');
        $make($this->jio, 'Nihar Surani', 'PSSPS7847N', $this->pilo, $pravin, 13428, 'refunded', null, '2026-10-23');
        $make($this->jio, 'Om Vaghani', 'CEAPV6462B', $this->om, $pravin, 187992, 'allotted', 23, '2026-10-23');
        $make($this->jio, 'Isha Desai', 'CWUPD7942L', $this->om, $pravin, 13428, 'applied', null, '2026-10-23');
        // An older IPO, so the list has more than one
        $make($this->paras, 'Old Friend', 'ABCDE1234F', $this->pilo, $pravin, 14553, 'refunded', null, '2026-10-10');
    }

    public function test_the_page_opens_on_the_list_of_ipos_that_were_applied_to(): void
    {
        Livewire::test(IpoDashboardPage::class)
            ->assertOk()
            ->assertSet('ipo', '')
            ->assertSee('Jio Platforms')
            ->assertSee('Paras Healthcare')
            ->assertDontSee('Account-wise')
            ->assertDontSee('Ved Mordiya');

        $this->get('/admin/ipo-dashboard')->assertOk();
    }

    public function test_the_list_counts_applications_and_results_per_ipo_newest_first(): void
    {
        $list = Livewire::test(IpoDashboardPage::class)->instance()->listing();

        $this->assertSame(['Jio Platforms', 'Paras Healthcare'], $list->pluck('name')->all());

        $jio = $list->firstWhere('name', 'Jio Platforms');
        $this->assertSame(5, $jio['apps']);
        $this->assertSame(241704.0, $jio['amount'], '4 x 13,428 + 187,992');
        $this->assertSame(3, $jio['allotted']);
        $this->assertSame(47, $jio['shares'], '12 + 12 + 23');
        $this->assertSame(1, $jio['not_allotted']);
        $this->assertSame(1, $jio['waiting']);

        $paras = $list->firstWhere('name', 'Paras Healthcare');
        $this->assertSame([1, 0, 1, 0], [$paras['apps'], $paras['allotted'], $paras['not_allotted'], $paras['waiting']]);
    }

    public function test_clicking_an_ipo_shows_how_many_applied_how_many_got_it_and_in_which_account(): void
    {
        $page = Livewire::test(IpoDashboardPage::class)->call('openIpo', $this->jio->id);
        $page->assertSet('ipo', (string) $this->jio->id);

        $report = $page->instance()->report();

        $this->assertSame(5, $report['data']['totals']['focus_count']);
        $this->assertSame(3, $report['allotment']['allotted']);
        $this->assertSame(47, $report['allotment']['allotted_shares']);
        $this->assertSame(1, $report['allotment']['not_allotted']);
        $this->assertSame(1, $report['allotment']['waiting']);

        $accounts = $report['accounts']->keyBy(fn (array $a) => $a['account']->name);

        $this->assertSame([3, 2, 24, 1, 0], [$accounts['pilo']['applied'], $accounts['pilo']['allotted'], $accounts['pilo']['shares'], $accounts['pilo']['not_allotted'], $accounts['pilo']['waiting']]);
        $this->assertEqualsCanonicalizing(['Ved Mordiya', 'Pratik Kanadiya'], $accounts['pilo']['allottees']->pluck('name')->all());

        $this->assertSame([2, 1, 23, 0, 1], [$accounts['Om Indus']['applied'], $accounts['Om Indus']['allotted'], $accounts['Om Indus']['shares'], $accounts['Om Indus']['not_allotted'], $accounts['Om Indus']['waiting']]);
        $this->assertSame('Om Vaghani', $accounts['Om Indus']['allottees'][0]['name']);

        // The account figures add up to the IPO's.
        $this->assertSame(5, $report['accounts']->sum('applied'));
        $this->assertSame(3, $report['accounts']->sum('allotted'));
    }

    public function test_the_detail_page_shows_the_numbers_the_accounts_and_the_source_breakdown(): void
    {
        Livewire::test(IpoDashboardPage::class)
            ->call('openIpo', $this->jio->id)
            ->assertOk()
            ->assertSee('Jio Platforms')
            ->assertSee('All IPOs')
            ->assertSee('Account-wise')
            ->assertSee('pilo')
            ->assertSee('Om Indus')
            ->assertSee('Ved Mordiya')
            ->assertSee('Source → Applicants')
            ->assertSee('Utsav bhai')
            ->assertSee('WhatsApp message')
            ->assertDontSee('Paras Healthcare')
            ->assertDontSee('Lot-wise');
    }

    public function test_back_returns_to_the_list(): void
    {
        Livewire::test(IpoDashboardPage::class)
            ->call('openIpo', $this->jio->id)
            ->call('backToList')
            ->assertSet('ipo', '')
            ->assertSee('Paras Healthcare');
    }

    public function test_an_ipo_can_be_opened_straight_from_the_url(): void
    {
        $this->get('/admin/ipo-dashboard?ipo='.$this->jio->id)->assertOk();

        Livewire::withQueryParams(['ipo' => (string) $this->jio->id])
            ->test(IpoDashboardPage::class)
            ->assertSet('ipo', (string) $this->jio->id)
            ->assertSee('Account-wise');
    }

    public function test_it_handles_a_login_with_no_applications(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test(IpoDashboardPage::class)
            ->assertOk()
            ->assertSee('No application has been made to any IPO yet');
    }
}
