<?php

namespace Tests\Feature;

use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\ApplicationSale;
use App\Models\AuditLog;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Om']);
        $this->actingAs($this->admin);
        $this->account = Account::create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 1000]);
    }

    private function application(): IpoApplication
    {
        return IpoApplication::create([
            'ipo_id' => Ipo::create(['name' => 'Test IPO', 'status' => 'open'])->id,
            'applicant_id' => Applicant::create(['name' => 'Friend', 'relation' => 'friend'])->id,
            'account_id' => $this->account->id,
            'amount' => 500,
            'applied_date' => '2026-10-20',
            'status' => 'applied',
        ]);
    }

    public function test_creating_a_record_is_logged_with_who_did_it(): void
    {
        $app = $this->application();

        $log = AuditLog::where('auditable_type', IpoApplication::class)->where('auditable_id', $app->id)->first();

        $this->assertSame('created', $log->event);
        $this->assertSame('Om', $log->actor_name);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('500.00', (string) $log->new_values['amount']);
    }

    public function test_an_edit_logs_only_the_fields_that_changed(): void
    {
        $app = $this->application();
        $app->update(['amount' => 900]);

        $log = AuditLog::where('auditable_id', $app->id)->where('event', 'updated')->latest('id')->first();

        $this->assertSame(['amount'], array_keys($log->new_values));
        $this->assertEquals(500, $log->old_values['amount']);
        $this->assertEquals(900, $log->new_values['amount']);
        $this->assertSame(['amount: 500.00 → 900.00'], $log->changeLines());
    }

    public function test_deleting_a_record_keeps_what_it_held(): void
    {
        $app = $this->application();
        $id = $app->id;
        $app->delete();

        $log = AuditLog::where('auditable_id', $id)->where('event', 'deleted')->first();

        $this->assertSame('500.00', (string) $log->old_values['amount']);
    }

    public function test_sales_and_their_effect_are_traceable(): void
    {
        $app = $this->application();
        $app->update(['status' => 'allotted', 'shares_allotted' => 5]);
        $app->sales()->create(['sale_date' => '2026-10-30', 'shares_sold' => 5, 'price_per_share' => 200, 'charges' => 10]);

        $sale = AuditLog::where('event', 'created')->where('auditable_type', ApplicationSale::class)->first();

        $this->assertNotNull($sale);
        $this->assertSame('990.00', (string) $sale->new_values['net_amount']);
    }

    public function test_noise_and_secrets_are_not_logged(): void
    {
        $source = Source::create(['name' => 'Uncle', 'profit_share_percent' => 50, 'password' => 'secret123', 'mobile' => '9876543210']);
        $source->update(['last_login_at' => now()]);

        $this->assertSame(0, AuditLog::where('event', 'updated')->where('auditable_type', Source::class)->count());

        $created = AuditLog::where('auditable_type', Source::class)->where('event', 'created')->first();
        $this->assertArrayNotHasKey('password', $created->new_values);
        $this->assertArrayNotHasKey('updated_at', $created->new_values);
    }

    public function test_each_login_only_sees_its_own_log(): void
    {
        $this->application();
        $this->assertGreaterThan(0, AuditLog::count());

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_activity_log_page_lists_changes_and_is_read_only(): void
    {
        $app = $this->application();
        $app->update(['amount' => 700]);

        Livewire::test(ListAuditLogs::class)
            ->assertOk()
            ->assertSee('amount: 500.00 → 700.00')
            ->assertCountTableRecords(AuditLog::count());

        $this->get('/admin/audit-logs')->assertOk();
        $this->assertFalse(AuditLogResource::canCreate());
    }
}
