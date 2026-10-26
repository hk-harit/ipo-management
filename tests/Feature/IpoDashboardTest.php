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

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->jio = Ipo::create(['name' => 'Jio Platforms', 'status' => 'open', 'issue_type' => 'EQ', 'min_amount' => 13428, 'lot_size' => 12, 'price_min' => 1065, 'price_max' => 1119, 'gmp' => 90]);
        $pravin = Source::create(['name' => 'Pravin masa', 'profit_share_percent' => 50]);
        $utsav = Source::create(['name' => 'Utsav bhai', 'profit_share_percent' => 40]);
        $pilo = Account::create(['name' => 'pilo', 'type' => 'bank', 'opening_balance' => 0]);
        $om = Account::create(['name' => 'Om Indus', 'type' => 'bank', 'opening_balance' => 0]);
        FundTransaction::create(['source_id' => $pravin->id, 'account_id' => $om->id, 'type' => 'given', 'amount' => 500000, 'transaction_date' => '2026-10-01']);

        $make = fn (string $name, string $pan, Account $acc, Source $src, float $amount, float $pct) => IpoApplication::create([
            'ipo_id' => $this->jio->id,
            'applicant_id' => Applicant::create(['name' => $name, 'relation' => 'friend', 'pan_number' => $pan])->id,
            'account_id' => $acc->id, 'source_id' => $src->id, 'amount' => $amount,
            'applied_date' => '2026-10-23', 'status' => 'applied', 'applicant_commission_percent' => $pct,
        ]);

        $make('Ved Mordiya', 'JQPPM7159J', $pilo, $utsav, 13428, 25);
        $make('Pratik Kanadiya', 'OYSPK2271R', $pilo, $utsav, 13428, 25);
        $make('Nihar Surani', 'PSSPS7847N', $pilo, $pravin, 13428, 0);
        $make('Om Vaghani', 'CEAPV6462B', $om, $pravin, 187992, 0);
        $make('Om Vaghani', 'CEAPV6462B', $om, $pravin, 15000, 0);
    }

    public function test_the_report_adds_up_by_lot_source_and_account(): void
    {
        $report = Livewire::test(IpoDashboardPage::class)->instance()->report();

        $this->assertSame(5, $report['data']['totals']['focus_count']);
        $this->assertSame(243276.0, $report['data']['totals']['focus_amount']);

        $lots = $report['lots']->keyBy('lots');
        $this->assertSame(4, $lots[1]['apps'], '₹13,428 x3 and the ₹15,000 one all count as 1 lot');
        $this->assertSame(1, $lots[14]['apps']);
        $this->assertSame(18, $report['total_lots']);

        $this->assertSame(4, $report['applicants'], 'Om Vaghani applied twice but is one PAN');
        $this->assertSame(2, $report['with_commission']);

        $this->assertEqualsWithDelta($report['data']['totals']['focus_amount'], $report['data']['source_groups']->sum('amount'), 0.001);
        $this->assertEqualsWithDelta($report['data']['totals']['focus_amount'], $report['accounts']->sum('focus_amount'), 0.001);
    }

    public function test_the_page_renders_every_section_for_the_ipo(): void
    {
        Livewire::test(IpoDashboardPage::class)
            ->assertOk()
            ->assertSet('ipo', (string) $this->jio->id)
            ->assertSee('Jio Platforms')
            ->assertSee('GMP')
            ->assertSee('Lot-wise')
            ->assertSee('Source-wise')
            ->assertSee('Source → Applicants')
            ->assertSee('Account-wise')
            ->assertSee('Ved Mordiya')
            ->assertSee('JQPPM7159J')
            ->assertSee('Pravin masa')
            ->assertSee('Utsav bhai')
            ->assertSee('25%');

        $this->get('/admin/ipo-dashboard')->assertOk();
    }

    public function test_it_handles_a_login_with_no_applications(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test(IpoDashboardPage::class)
            ->assertOk()
            ->assertSee('No application has been made');
    }
}
