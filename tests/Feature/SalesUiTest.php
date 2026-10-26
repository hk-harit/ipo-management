<?php

namespace Tests\Feature;

use App\Filament\Resources\IpoApplicationResource;
use App\Filament\Resources\IpoApplicationResource\Pages\EditIpoApplication;
use App\Filament\Resources\IpoApplicationResource\RelationManagers\SalesRelationManager;
use App\Filament\Widgets\AllottedAwaitingSaleWidget;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalesUiTest extends TestCase
{
    use RefreshDatabase;

    private IpoApplication $application;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $account = Account::create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 10000]);

        $this->application = IpoApplication::create([
            'ipo_id' => Ipo::create(['name' => 'Test IPO', 'status' => 'listed'])->id,
            'applicant_id' => Applicant::create(['name' => 'Friend', 'relation' => 'friend'])->id,
            'account_id' => $account->id,
            'amount' => 3000,
            'applied_date' => '2026-10-20',
            'status' => 'allotted',
            'shares_allotted' => 10,
        ]);
    }

    public function test_the_dashboard_record_sale_button_sells_part_then_all(): void
    {
        Livewire::test(AllottedAwaitingSaleWidget::class)
            ->callTableAction('record_sale', $this->application, data: ['sale_date' => '2026-10-30', 'shares_sold' => 4, 'price_per_share' => 400, 'charges' => 20])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Sale recorded');

        $this->assertSame('allotted', $this->application->fresh()->status);

        Livewire::test(AllottedAwaitingSaleWidget::class)
            ->callTableAction('record_sale', $this->application, data: ['sale_date' => '2026-10-31', 'shares_sold' => 6, 'price_per_share' => 420, 'charges' => 25])
            ->assertNotified('Fully sold — marked as Sold');

        $this->assertSame('sold', $this->application->fresh()->status);
        $this->assertSame(1075.0, $this->application->fresh()->profit);
    }

    public function test_the_form_refuses_more_shares_than_are_left(): void
    {
        Livewire::test(AllottedAwaitingSaleWidget::class)
            ->callTableAction('record_sale', $this->application, data: ['sale_date' => '2026-10-30', 'shares_sold' => 11, 'price_per_share' => 400, 'charges' => 0])
            ->assertHasTableActionErrors(['shares_sold']);

        $this->assertSame(0, $this->application->sales()->count());
    }

    public function test_the_sales_tab_lists_creates_and_deletes_sales(): void
    {
        $component = Livewire::test(SalesRelationManager::class, ['ownerRecord' => $this->application, 'pageClass' => EditIpoApplication::class]);

        $component
            ->callTableAction('create', data: ['sale_date' => '2026-10-30', 'shares_sold' => 10, 'price_per_share' => 400, 'charges' => 50])
            ->assertHasNoTableActionErrors();

        $sale = $this->application->sales()->first();
        $this->assertSame('3950.00', (string) $sale->net_amount);
        $this->assertSame('sold', $this->application->fresh()->status);

        $component->callTableAction('delete', $sale);

        $this->assertSame('allotted', $this->application->fresh()->status);
    }

    public function test_saving_a_stale_edit_form_does_not_undo_a_sale(): void
    {
        // The edit form was opened while the application was still "allotted"...
        $stale = IpoApplication::find($this->application->id);

        $this->application->sales()->create(['sale_date' => '2026-10-30', 'shares_sold' => 10, 'price_per_share' => 400, 'charges' => 0]);
        $this->assertSame('sold', $this->application->fresh()->status);

        // ...and is saved afterwards with its old status.
        $stale->notes = 'edited later';
        $stale->save();

        $this->assertSame('sold', $this->application->fresh()->status);
    }

    public function test_the_application_edit_page_opens_with_the_sales_tab(): void
    {
        $this->get(IpoApplicationResourceUrl::edit($this->application))->assertOk();
    }
}

/** Tiny helper so the test reads clearly. */
final class IpoApplicationResourceUrl
{
    public static function edit(IpoApplication $app): string
    {
        return IpoApplicationResource::getUrl('edit', ['record' => $app]);
    }
}
