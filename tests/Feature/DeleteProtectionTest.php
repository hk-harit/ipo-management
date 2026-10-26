<?php

namespace Tests\Feature;

use App\Filament\Resources\ApplicantResource\Pages\EditApplicant;
use App\Filament\Resources\ApplicantResource\Pages\ListApplicants;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\Expense;
use App\Models\FundTransaction;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeleteProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function application(Account $account, Applicant $applicant, Ipo $ipo, ?Source $source = null): IpoApplication
    {
        return IpoApplication::create([
            'ipo_id' => $ipo->id,
            'applicant_id' => $applicant->id,
            'account_id' => $account->id,
            'source_id' => $source?->id,
            'amount' => 1000,
            'applied_date' => '2026-10-20',
            'status' => 'applied',
        ]);
    }

    public function test_records_with_no_history_can_still_be_deleted(): void
    {
        $this->assertTrue(Account::create(['name' => 'A', 'type' => 'bank', 'opening_balance' => 0])->delete());
        $this->assertTrue(Applicant::create(['name' => 'P', 'relation' => 'friend'])->delete());
        $this->assertTrue(Ipo::create(['name' => 'I', 'status' => 'open'])->delete());
        $this->assertTrue(Source::create(['name' => 'S', 'profit_share_percent' => 50])->delete());
    }

    public function test_an_account_applicant_ipo_and_source_with_applications_survive_a_delete(): void
    {
        $account = Account::create(['name' => 'A', 'type' => 'bank', 'opening_balance' => 0]);
        $applicant = Applicant::create(['name' => 'P', 'relation' => 'friend']);
        $ipo = Ipo::create(['name' => 'I', 'status' => 'open']);
        $source = Source::create(['name' => 'S', 'profit_share_percent' => 50]);
        $this->application($account, $applicant, $ipo, $source);

        foreach ([$account, $applicant, $ipo, $source] as $record) {
            $this->assertFalse($record->delete());
            $this->assertNotNull($record->deletionBlockedReason());
            $this->assertTrue($record->exists, class_basename($record).' must still exist');
        }

        $this->assertSame(1, IpoApplication::count(), 'nothing cascaded away');
    }

    public function test_an_account_with_money_movements_or_a_source_with_funds_is_protected(): void
    {
        $account = Account::create(['name' => 'A', 'type' => 'bank', 'opening_balance' => 0]);
        $source = Source::create(['name' => 'S', 'profit_share_percent' => 50]);
        FundTransaction::create(['source_id' => $source->id, 'account_id' => $account->id, 'type' => 'given', 'amount' => 100, 'transaction_date' => '2026-10-01']);

        $this->assertFalse($account->delete());
        $this->assertFalse($source->delete());

        $other = Account::create(['name' => 'B', 'type' => 'cash', 'opening_balance' => 0]);
        Expense::create(['account_id' => $other->id, 'type' => 'expense', 'amount' => 5, 'expense_date' => '2026-10-02']);
        $this->assertFalse($other->delete());
    }

    public function test_deleting_the_history_first_unblocks_the_record(): void
    {
        $account = Account::create(['name' => 'A', 'type' => 'bank', 'opening_balance' => 0]);
        $applicant = Applicant::create(['name' => 'P', 'relation' => 'friend']);
        $ipo = Ipo::create(['name' => 'I', 'status' => 'open']);
        $this->application($account, $applicant, $ipo)->delete();

        $this->assertTrue($account->delete());
    }

    public function test_the_delete_buttons_in_the_panel_refuse_and_explain(): void
    {
        $account = Account::create(['name' => 'A', 'type' => 'bank', 'opening_balance' => 0]);
        $applicant = Applicant::create(['name' => 'P', 'relation' => 'friend']);
        $ipo = Ipo::create(['name' => 'I', 'status' => 'open']);
        $this->application($account, $applicant, $ipo);

        Livewire::test(EditApplicant::class, ['record' => $applicant->id])
            ->callAction(DeleteAction::class)
            ->assertNotified('Cannot delete — it has history');

        Livewire::test(ListApplicants::class)
            ->callTableBulkAction('delete', [$applicant])
            ->assertNotified('Cannot delete — it has history');

        $this->assertTrue($applicant->fresh()->exists);
    }
}
