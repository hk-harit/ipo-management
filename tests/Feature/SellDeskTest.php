<?php

namespace Tests\Feature;

use App\Filament\Pages\SellDeskPage;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** After listing: request the sell first, then mark it sold, all on one screen. */
class SellDeskTest extends TestCase
{
    use RefreshDatabase;

    private Account $pilo;

    private Account $om;

    private Ipo $jio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->pilo = Account::create(['name' => 'pilo', 'type' => 'bank', 'opening_balance' => 0]);
        $this->om = Account::create(['name' => 'Om Indus', 'type' => 'bank', 'opening_balance' => 0]);
        $this->jio = Ipo::create(['name' => 'Jio Platforms', 'status' => 'listed']);
    }

    private function allotted(string $name, Account $account, int $shares = 12, string $status = 'allotted', array $extra = []): IpoApplication
    {
        return IpoApplication::create(array_merge([
            'ipo_id' => $this->jio->id,
            'applicant_id' => Applicant::create(['name' => $name, 'relation' => 'friend'])->id,
            'account_id' => $account->id,
            'amount' => 13428,
            'applied_date' => '2026-10-23',
            'status' => $status,
            'shares_allotted' => $shares,
        ], $extra));
    }

    public function test_the_page_opens_and_counts_each_stage(): void
    {
        $this->allotted('Asha', $this->pilo);
        $this->allotted('Bina', $this->pilo, 23, 'allotted', ['sell_requested_at' => now()]);
        $this->allotted('Chandni', $this->om, 12, 'sold', ['shares_sold' => 12]);
        $this->allotted('Dipa', $this->om, 0, 'not_allotted');

        $this->get('/admin/sell-desk')->assertOk();

        $counts = Livewire::test(SellDeskPage::class)->instance()->counts();

        $this->assertSame(1, $counts['request']['apps']);
        $this->assertSame(12, $counts['request']['shares']);
        $this->assertSame(1, $counts['waiting']['apps']);
        $this->assertSame(23, $counts['waiting']['shares']);
        $this->assertSame(1, $counts['sold']['apps']);
    }

    public function test_requesting_moves_an_application_to_the_waiting_stage_and_back(): void
    {
        $a = $this->allotted('Asha', $this->pilo);

        $page = Livewire::test(SellDeskPage::class)->call('markRequested', $a->id);
        $this->assertNotNull($a->fresh()->sell_requested_at);
        $this->assertSame(SellDeskPage::WAITING, SellDeskPage::stageOf($a->fresh()));

        $page->call('undoRequested', $a->id);
        $this->assertNull($a->fresh()->sell_requested_at);
    }

    public function test_requesting_a_whole_account_leaves_the_other_account_alone(): void
    {
        $a = $this->allotted('Asha', $this->pilo);
        $b = $this->allotted('Bina', $this->pilo);
        $c = $this->allotted('Chandni', $this->om);

        Livewire::test(SellDeskPage::class)->call('requestAccount', $this->pilo->id);

        $this->assertNotNull($a->fresh()->sell_requested_at);
        $this->assertNotNull($b->fresh()->sell_requested_at);
        $this->assertNull($c->fresh()->sell_requested_at);
    }

    public function test_marking_sold_records_the_sale_credits_the_account_and_needs_a_price(): void
    {
        $a = $this->allotted('Asha', $this->pilo, 12, 'allotted', ['sell_requested_at' => now()]);

        $page = Livewire::test(SellDeskPage::class)->call('markSold', $a->id);
        $this->assertSame(0, $a->sales()->count(), 'no price, no sale');

        $page->set('price', '1000')->set('charges', '20')->call('markSold', $a->id);

        $a->refresh();
        $this->assertSame('sold', $a->status);
        $this->assertSame(1, $a->sales()->count());
        $this->assertEqualsWithDelta(12000 - 20, (float) $a->sales()->first()->net_amount, 0.001);
        $this->assertEqualsWithDelta(-13428 + 11980, $this->pilo->fresh()->current_balance, 0.001, 'applied 13428, sold for 11980 net');
        $this->assertSame(SellDeskPage::SOLD, SellDeskPage::stageOf($a));
    }

    public function test_all_sold_in_an_account_uses_the_top_price_and_a_row_price_can_differ(): void
    {
        $a = $this->allotted('Asha', $this->pilo, 10, 'allotted', ['sell_requested_at' => now()]);
        $b = $this->allotted('Bina', $this->pilo, 10, 'allotted', ['sell_requested_at' => now()]);
        $c = $this->allotted('Chandni', $this->pilo, 10); // request never sent
        $d = $this->allotted('Dipa', $this->om, 10, 'allotted', ['sell_requested_at' => now()]);

        Livewire::test(SellDeskPage::class)
            ->set('price', '100')
            ->set('prices', [$b->id => '120'])
            ->call('soldAccount', $this->pilo->id);

        $this->assertEqualsWithDelta(1000.0, (float) $a->fresh()->sales()->sum('net_amount'), 0.001);
        $this->assertEqualsWithDelta(1200.0, (float) $b->fresh()->sales()->sum('net_amount'), 0.001);
        $this->assertSame(0, $c->sales()->count(), 'not requested yet, so not sold');
        $this->assertSame(0, $d->sales()->count(), 'another account');
    }

    public function test_a_partly_sold_application_stays_open_until_the_rest_sells(): void
    {
        $a = $this->allotted('Asha', $this->pilo, 12, 'allotted', ['sell_requested_at' => now()]);
        $a->sales()->create(['sale_date' => now(), 'shares_sold' => 5, 'price_per_share' => 100]);

        $this->assertSame(SellDeskPage::WAITING, SellDeskPage::stageOf($a->fresh()));

        Livewire::test(SellDeskPage::class)->set('price', '100')->call('markSold', $a->id);

        $this->assertSame('sold', $a->fresh()->status);
        $this->assertSame(12, (int) $a->fresh()->sales()->sum('shares_sold'));
    }
}
