<?php

namespace Tests\Feature;

use App\Filament\Pages\MoneyDeskPage;
use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\Applicant;
use App\Models\FundTransaction;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Who has to put how much money where, and what each source is to be paid. */
class MoneyDeskTest extends TestCase
{
    use RefreshDatabase;

    private Account $sbi;

    private Account $pilo;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->sbi = Account::create(['name' => 'Om SBI', 'type' => 'bank', 'opening_balance' => 0]);
        $this->pilo = Account::create(['name' => 'pilo', 'type' => 'bank', 'holder_name' => 'pilo', 'opening_balance' => 50000]);
        $this->cash = Account::create(['name' => 'Cash', 'type' => 'cash', 'opening_balance' => 9000]);
    }

    public function test_the_page_opens_and_defaults_to_the_sbi_bank(): void
    {
        $this->get('/admin/money-desk')->assertOk();

        $page = Livewire::test(MoneyDeskPage::class);
        $this->assertSame((string) $this->sbi->id, $page->get('destination'));
    }

    public function test_the_list_skips_the_destination_and_cash_and_shows_the_free_balance(): void
    {
        $rows = Livewire::test(MoneyDeskPage::class)->instance()->collectList();

        $this->assertSame(['pilo'], $rows->pluck('name')->all());
        $this->assertSame(50000.0, $rows->first()['free']);
    }

    public function test_marking_money_received_moves_it_to_the_destination_bank(): void
    {
        Livewire::test(MoneyDeskPage::class)
            ->set('amounts', [$this->pilo->id.'-'.$this->sbi->id => '30000'])
            ->call('collectedLine', $this->pilo->id, $this->sbi->id);

        $this->assertEqualsWithDelta(20000.0, $this->pilo->fresh()->current_balance, 0.001);
        $this->assertEqualsWithDelta(30000.0, $this->sbi->fresh()->current_balance, 0.001);
    }

    public function test_a_blank_amount_moves_the_whole_free_balance(): void
    {
        Livewire::test(MoneyDeskPage::class)->call('collectedLine', $this->pilo->id, $this->sbi->id);

        $this->assertEqualsWithDelta(0.0, $this->pilo->fresh()->current_balance, 0.001);
        $this->assertEqualsWithDelta(50000.0, $this->sbi->fresh()->current_balance, 0.001);
    }

    public function test_money_goes_back_to_the_bank_it_came_from_and_the_extra_to_the_chosen_bank(): void
    {
        $indus = Account::create(['name' => 'Om Indus', 'type' => 'bank', 'opening_balance' => 100000]);
        $tirth = Account::create(['name' => 'Tirth', 'type' => 'bank', 'opening_balance' => 2000]);

        // 20,000 came from SBI and 10,000 from Om Indus; 5,000 went back to SBI. The 2,000 is extra (profit).
        AccountTransfer::create(['from_account_id' => $this->sbi->id, 'to_account_id' => $tirth->id, 'amount' => 20000, 'transfer_date' => '2026-10-22']);
        AccountTransfer::create(['from_account_id' => $indus->id, 'to_account_id' => $tirth->id, 'amount' => 10000, 'transfer_date' => '2026-10-22']);
        AccountTransfer::create(['from_account_id' => $tirth->id, 'to_account_id' => $this->sbi->id, 'amount' => 5000, 'transfer_date' => '2026-10-23']);

        $row = Livewire::test(MoneyDeskPage::class)->instance()->collectList()->firstWhere('name', 'Tirth');

        $this->assertSame(27000.0, $row['free']);
        $this->assertSame(
            [['Om SBI', 17000.0], ['Om Indus', 10000.0]],
            collect($row['lines'])->map(fn ($l) => [$l['bank'], $l['amount']])->all(),
            '15,000 owed to SBI + 2,000 extra to the chosen bank (SBI), 10,000 owed to Om Indus'
        );
    }

    private function soldThroughSource(): Source
    {
        $source = Source::create(['name' => 'Uncle', 'profit_share_percent' => 50]);
        FundTransaction::create(['source_id' => $source->id, 'account_id' => $this->pilo->id, 'type' => 'given', 'amount' => 40000, 'transaction_date' => '2026-10-20']);

        IpoApplication::create([
            'ipo_id' => Ipo::create(['name' => 'Jio', 'status' => 'listed'])->id,
            'applicant_id' => Applicant::create(['name' => 'Friend', 'relation' => 'friend'])->id,
            'account_id' => $this->pilo->id,
            'source_id' => $source->id,
            'amount' => 13428,
            'applied_date' => '2026-10-23',
            'status' => 'allotted',
            'shares_allotted' => 12,
        ])->sales()->create(['sale_date' => '2026-10-28', 'shares_sold' => 12, 'price_per_share' => 1358, 'charges' => 60]);

        return $source;
    }

    public function test_a_source_is_owed_the_money_back_plus_the_profit_share(): void
    {
        $this->soldThroughSource();

        $row = Livewire::test(MoneyDeskPage::class)->instance()->sourceList()->first();

        $this->assertSame(40000.0, $row['principal']);
        $this->assertSame(1404.0, $row['profit'], '(16236 - 13428) x 50%');
        $this->assertSame(41404.0, $row['total']);
    }

    public function test_paying_a_source_records_the_money_back_and_the_profit_and_clears_the_dues(): void
    {
        $source = $this->soldThroughSource();
        $before = $this->sbi->fresh()->current_balance;

        Livewire::test(MoneyDeskPage::class)
            ->set('payFrom', (string) $this->sbi->id)
            ->call('paySource', $source->id);

        $source = $source->fresh();
        $this->assertEqualsWithDelta(0.0, $source->balance_due, 0.001);
        $this->assertEqualsWithDelta(0.0, $source->profit_share_pending, 0.001);
        $this->assertEqualsWithDelta($before - 41404, $this->sbi->fresh()->current_balance, 0.001);
        $this->assertCount(0, Livewire::test(MoneyDeskPage::class)->instance()->sourceList());
    }

    public function test_a_part_payment_leaves_the_rest_due(): void
    {
        $source = $this->soldThroughSource();

        Livewire::test(MoneyDeskPage::class)
            ->set('payFrom', (string) $this->sbi->id)
            ->set('principal', [$source->id => '10000'])
            ->set('profit', [$source->id => '0'])
            ->call('paySource', $source->id);

        $this->assertEqualsWithDelta(30000.0, $source->fresh()->balance_due, 0.001);
        $this->assertEqualsWithDelta(1404.0, $source->fresh()->profit_share_pending, 0.001);
    }
}
