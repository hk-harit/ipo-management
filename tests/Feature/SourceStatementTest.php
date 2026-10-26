<?php

namespace Tests\Feature;

use App\Filament\Resources\SourceResource;
use App\Filament\Resources\SourceResource\Pages\EditSource;
use App\Filament\Resources\SourceResource\Pages\SourceStatement;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\FundTransaction;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\ProfitPayout;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SourceStatementTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $account = Account::create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 0]);
        $applicant = Applicant::create(['name' => 'Friend', 'relation' => 'friend']);
        $ipo = Ipo::create(['name' => 'Test IPO', 'status' => 'listed']);
        $this->source = Source::create(['name' => 'Uncle', 'relation' => 'Uncle', 'mobile' => '9876543210', 'profit_share_percent' => 50]);

        FundTransaction::create(['source_id' => $this->source->id, 'account_id' => $account->id, 'type' => 'given', 'amount' => 10000, 'transaction_date' => '2026-10-01']);
        FundTransaction::create(['source_id' => $this->source->id, 'account_id' => $account->id, 'type' => 'returned', 'amount' => 2000, 'transaction_date' => '2026-10-02']);

        $sold = IpoApplication::create([
            'ipo_id' => $ipo->id, 'applicant_id' => $applicant->id, 'account_id' => $account->id, 'source_id' => $this->source->id,
            'amount' => 3000, 'applied_date' => '2026-10-05', 'status' => 'allotted', 'shares_allotted' => 10,
        ]);
        $sold->sales()->create(['sale_date' => '2026-10-20', 'shares_sold' => 10, 'price_per_share' => 400, 'charges' => 100]);

        ProfitPayout::create(['source_id' => $this->source->id, 'account_id' => $account->id, 'amount' => 200, 'payout_date' => '2026-10-25']);
    }

    public function test_the_statement_page_shows_the_figures(): void
    {
        // profit = 3900 - 3000 = 900, source 50% = 450, paid 200 -> pending 250; due 8000.
        Livewire::test(SourceStatement::class, ['record' => $this->source->id])
            ->assertOk()
            ->assertSee('Uncle')
            ->assertSee('₹10,000.00')
            ->assertSee('₹8,000.00')
            ->assertSee('₹450.00')
            ->assertSee('₹250.00')
            ->assertSee('₹8,250.00') // total payable: 8000 + 250
            ->assertSee('Test IPO');

        $this->get(SourceResource::getUrl('statement', ['record' => $this->source]))->assertOk();
    }

    public function test_the_whatsapp_text_carries_the_totals(): void
    {
        $text = Livewire::test(SourceStatement::class, ['record' => $this->source->id])->instance()->whatsappText();

        $this->assertStringContainsString('Uncle', $text);
        $this->assertStringContainsString('Still with us: ₹8,000.00', $text);
        $this->assertStringContainsString('Profit pending: ₹250.00', $text);
        $this->assertStringContainsString('Total payable to you: ₹8,250.00', $text);
    }

    public function test_the_edit_page_links_to_the_statement(): void
    {
        Livewire::test(EditSource::class, ['record' => $this->source->id])
            ->assertActionExists('statement');
    }

    public function test_another_login_cannot_open_this_statement(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(SourceStatement::class, ['record' => $this->source->id]);
    }
}
