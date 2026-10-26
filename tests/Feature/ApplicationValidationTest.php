<?php

namespace Tests\Feature;

use App\Filament\Pages\BulkIpoApply;
use App\Filament\Resources\FundTransactionResource\Pages\CreateFundTransaction;
use App\Filament\Resources\IpoApplicationResource\Pages\CreateIpoApplication;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApplicationValidationTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Applicant $applicant;

    private Ipo $ipo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->account = Account::create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 1000]);
        $this->applicant = Applicant::create(['name' => 'Friend', 'relation' => 'friend']);
        $this->ipo = Ipo::create(['name' => 'Test IPO', 'status' => 'open']);
    }

    private function formData(array $extra = []): array
    {
        return array_merge([
            'ipo_id' => $this->ipo->id,
            'applicant_id' => $this->applicant->id,
            'account_id' => $this->account->id,
            'funding_type' => 'direct',
            'amount' => 500,
            'applied_date' => '2026-10-20',
            'status' => 'applied',
        ], $extra);
    }

    public function test_a_normal_application_saves(): void
    {
        Livewire::test(CreateIpoApplication::class)
            ->fillForm($this->formData())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, IpoApplication::count());
    }

    public function test_zero_or_negative_amounts_are_rejected(): void
    {
        foreach ([0, -100] as $amount) {
            Livewire::test(CreateIpoApplication::class)
                ->fillForm($this->formData(['amount' => $amount]))
                ->call('create')
                ->assertHasFormErrors(['amount']);
        }

        Livewire::test(CreateFundTransaction::class)
            ->fillForm(['type' => 'given', 'amount' => -5, 'transaction_date' => '2026-10-01'])
            ->call('create')
            ->assertHasFormErrors(['amount']);

        $this->assertSame(0, IpoApplication::count());
    }

    public function test_shares_sold_cannot_exceed_shares_allotted(): void
    {
        Livewire::test(CreateIpoApplication::class)
            ->fillForm($this->formData(['status' => 'sold', 'shares_allotted' => 10, 'shares_sold' => 12, 'return_amount' => 5000]))
            ->call('create')
            ->assertHasFormErrors(['shares_sold']);
    }

    public function test_source_share_and_applicant_commission_cannot_pass_100_percent(): void
    {
        $source = Source::create(['name' => 'Uncle', 'profit_share_percent' => 80]);

        Livewire::test(CreateIpoApplication::class)
            ->fillForm($this->formData(['source_id' => $source->id, 'applicant_commission_percent' => 30]))
            ->call('create')
            ->assertHasFormErrors(['applicant_commission_percent']);

        Livewire::test(CreateIpoApplication::class)
            ->fillForm($this->formData(['source_id' => $source->id, 'applicant_commission_percent' => 20]))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_applying_beyond_the_balance_still_saves_but_warns(): void
    {
        Livewire::test(CreateIpoApplication::class)
            ->fillForm($this->formData(['amount' => 5000]))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Account is overdrawn');

        $this->assertSame(1, IpoApplication::count());
    }

    public function test_a_second_application_for_the_same_ipo_is_allowed_with_a_note(): void
    {
        IpoApplication::create($this->formData());

        Livewire::test(CreateIpoApplication::class)
            ->fillForm($this->formData(['amount' => 200]))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Applicant already applied to this IPO');

        $this->assertSame(2, IpoApplication::count());
    }

    public function test_bulk_apply_creates_every_row(): void
    {
        $second = Applicant::create(['name' => 'Second', 'relation' => 'friend']);

        Livewire::test(BulkIpoApply::class)
            ->fillForm([
                'ipo_id' => $this->ipo->id,
                'applied_date' => '2026-10-20',
                'rows' => [
                    ['applicant_id' => $this->applicant->id, 'account_id' => $this->account->id, 'funding_type' => 'direct', 'amount' => 100],
                    ['applicant_id' => $second->id, 'account_id' => $this->account->id, 'funding_type' => 'direct', 'amount' => 200],
                ],
            ])
            ->call('submit')
            ->assertNotified('2 application(s) created');

        $this->assertSame(2, IpoApplication::count());
        $this->assertSame(700.0, $this->account->current_balance);
    }
}
