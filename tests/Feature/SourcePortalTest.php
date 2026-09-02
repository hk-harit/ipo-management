<?php

namespace Tests\Feature;

use App\Filament\Resources\SourceResource\Pages\EditSource;
use App\Filament\Source\Pages\Auth\SourceLogin;
use App\Filament\Source\Pages\MyApplications;
use App\Filament\Source\Widgets\IpoWiseSummary;
use App\Filament\Source\Widgets\PortalStats;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\FundTransaction;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SourcePortalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        // A real request to /source picks the panel up from the URL; a Livewire
        // component test has no URL, so point it at the right panel by hand —
        // otherwise Filament::auth() hands back the admin panel's `web` guard.
        Filament::setCurrentPanel(Filament::getPanel('source'));

        $this->admin = User::factory()->create();

        // Push the sources' ids well clear of the users' ids. When admin #1 owns
        // source #1, a scope that mistakenly filters user_id by the *source's* id
        // still passes — this keeps that bug visible.
        foreach (range(1, 20) as $i) {
            Source::create(['user_id' => $this->admin->id, 'name' => 'Filler '.$i]);
        }

        $this->source = $this->makeSourceWithData($this->admin, 'Uncle A', '9876543210');
    }

    /**
     * One admin, one funding source, and a full round trip: money handed in,
     * applied to an IPO, allotted, sold, half the profit theirs.
     */
    private function makeSourceWithData(User $owner, string $name, string $mobile): Source
    {
        $source = Source::create([
            'user_id' => $owner->id,
            'name' => $name,
            'relation' => 'Uncle',
            'mobile' => $mobile,
            'password' => 'secret123',
            'portal_enabled' => true,
            'profit_share_percent' => 50,
        ]);

        $account = Account::create([
            'user_id' => $owner->id,
            'name' => $name.' Bank',
            'type' => 'bank',
            'opening_balance' => 0,
        ]);

        $applicant = Applicant::create([
            'user_id' => $owner->id,
            'name' => $name.' Friend',
            'relation' => 'friend',
        ]);

        $ipo = Ipo::create([
            'user_id' => $owner->id,
            'name' => $name.' IPO',
            'status' => 'listed',
        ]);

        FundTransaction::create([
            'user_id' => $owner->id,
            'source_id' => $source->id,
            'account_id' => $account->id,
            'type' => 'given',
            'amount' => 100000,
            'transaction_date' => now()->subDays(10),
        ]);

        FundTransaction::create([
            'user_id' => $owner->id,
            'source_id' => $source->id,
            'account_id' => $account->id,
            'type' => 'returned',
            'amount' => 30000,
            'transaction_date' => now()->subDays(2),
        ]);

        IpoApplication::create([
            'user_id' => $owner->id,
            'ipo_id' => $ipo->id,
            'applicant_id' => $applicant->id,
            'account_id' => $account->id,
            'source_id' => $source->id,
            'amount' => 15000,
            'applied_date' => now()->subDays(8),
            'status' => 'sold',
            'shares_allotted' => 100,
            'shares_sold' => 100,
            'return_amount' => 19000,
            'return_date' => now()->subDays(3),
        ]);

        return $source->refresh();
    }

    public function test_a_source_can_sign_in_with_their_mobile_number(): void
    {
        Livewire::test(SourceLogin::class)
            ->fillForm(['login' => '9876543210', 'password' => 'secret123'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertTrue(auth('source')->check());
        $this->assertSame($this->source->id, auth('source')->id());
    }

    public function test_a_source_can_sign_in_with_a_mobile_typed_with_country_code_and_spaces(): void
    {
        Livewire::test(SourceLogin::class)
            ->fillForm(['login' => '+91 98765 43210', 'password' => 'secret123'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertTrue(auth('source')->check());
    }

    public function test_a_source_can_sign_in_with_their_email(): void
    {
        $this->source->forceFill(['email' => 'uncle@example.com'])->save();

        Livewire::test(SourceLogin::class)
            ->fillForm(['login' => 'uncle@example.com', 'password' => 'secret123'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertTrue(auth('source')->check());
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        Livewire::test(SourceLogin::class)
            ->fillForm(['login' => '9876543210', 'password' => 'wrong'])
            ->call('authenticate')
            ->assertHasFormErrors(['login']);

        $this->assertFalse(auth('source')->check());
    }

    public function test_a_source_with_the_portal_switched_off_cannot_sign_in(): void
    {
        $this->source->forceFill(['portal_enabled' => false])->save();

        Livewire::test(SourceLogin::class)
            ->fillForm(['login' => '9876543210', 'password' => 'secret123'])
            ->call('authenticate')
            ->assertHasFormErrors(['login']);

        $this->assertFalse(auth('source')->check());
    }

    public function test_a_deactivated_source_cannot_sign_in(): void
    {
        $this->source->forceFill(['is_active' => false])->save();

        Livewire::test(SourceLogin::class)
            ->fillForm(['login' => '9876543210', 'password' => 'secret123'])
            ->call('authenticate')
            ->assertHasFormErrors(['login']);

        $this->assertFalse(auth('source')->check());
    }

    public function test_signing_in_records_the_last_login_time(): void
    {
        $this->assertNull($this->source->last_login_at);

        Livewire::test(SourceLogin::class)
            ->fillForm(['login' => '9876543210', 'password' => 'secret123'])
            ->call('authenticate');

        $this->assertNotNull($this->source->fresh()->last_login_at);
    }

    public function test_every_portal_page_loads_for_a_signed_in_source(): void
    {
        $this->actingAs($this->source, 'source');

        foreach (['/source', '/source/my-applications', '/source/my-ledger', '/source/my-accounts'] as $url) {
            $this->get($url)->assertSuccessful();
        }
    }

    public function test_the_portal_is_closed_to_visitors_who_are_not_signed_in(): void
    {
        $this->get('/source')->assertRedirect('/source/login');
        $this->get('/source/my-applications')->assertRedirect('/source/login');
    }

    public function test_an_admin_cannot_use_the_source_portal(): void
    {
        $this->actingAs($this->admin);

        $this->get('/source')->assertRedirect('/source/login');
    }

    public function test_a_source_cannot_reach_the_admin_panel(): void
    {
        $this->actingAs($this->source, 'source');

        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_the_portal_shows_the_sources_own_figures(): void
    {
        $source = $this->source;

        $this->assertSame(100000.0, $source->total_given);
        $this->assertSame(30000.0, $source->total_returned);
        $this->assertSame(70000.0, $source->balance_due);
        $this->assertSame(15000.0, $source->total_applied);
        $this->assertSame(1, $source->allotted_count);
        // Profit 19000 - 15000 = 4000, half of which is theirs.
        $this->assertSame(2000.0, $source->total_profit_share_due);
    }

    public function test_the_ipo_wise_summary_groups_applications_by_ipo(): void
    {
        $summary = $this->source->ipoWiseSummary();

        $this->assertCount(1, $summary);
        $this->assertSame('Uncle A IPO', $summary[0]['ipo']);
        $this->assertSame(15000.0, $summary[0]['applied']);
        $this->assertSame(1, $summary[0]['allotted_apps']);
        $this->assertSame(100, $summary[0]['shares_allotted']);
        $this->assertSame(2000.0, $summary[0]['my_share']);
    }

    public function test_the_ledger_carries_a_running_balance(): void
    {
        $ledger = $this->source->buildLedger();

        $this->assertCount(2, $ledger);
        $this->assertSame(100000.0, $ledger[0]['balance']);
        $this->assertSame(70000.0, $ledger[1]['balance']);
    }

    /**
     * The important one. A Source logs in on its own guard, which leaves the
     * default `web` guard empty — so the tenant scope has to key off the
     * source's owner, or the portal would show every admin's data.
     */
    public function test_a_signed_in_source_never_sees_another_admins_data(): void
    {
        $otherAdmin = User::factory()->create();
        $this->makeSourceWithData($otherAdmin, 'Stranger', '9000000000');

        $this->actingAs($this->source, 'source');

        $this->assertSame(1, IpoApplication::count());
        $this->assertSame(1, Ipo::count());
        $this->assertSame(2, FundTransaction::count());
        $this->assertSame(1, Account::count());
        $this->assertSame($this->admin->id, Ipo::first()->user_id);
    }

    public function test_a_source_only_sees_applications_funded_by_them(): void
    {
        // A second source under the same admin, with their own application.
        $sibling = $this->makeSourceWithData($this->admin, 'Brother B', '9111111111');

        $this->actingAs($this->source, 'source');

        $this->assertCount(1, $this->source->ipoApplications()->get());
        $this->assertSame(15000.0, $this->source->total_applied);
        $this->assertNotSame($sibling->id, $this->source->ipoApplications()->first()->source_id);
    }

    public function test_the_admin_panel_still_sees_everything_it_owns(): void
    {
        $this->makeSourceWithData($this->admin, 'Brother B', '9111111111');

        $this->actingAs($this->admin);

        $this->assertSame(2, IpoApplication::count());
        $this->assertSame(2, Ipo::count());
        $this->get('/admin/sources')->assertSuccessful();
    }

    /**
     * The pages have to render the real figures, not just return 200. A tenant
     * scope keyed off the wrong id still gives a perfectly healthy-looking page
     * full of zeroes.
     */
    public function test_the_ledger_page_renders_the_sources_real_figures(): void
    {
        $this->actingAs($this->source, 'source');

        $this->get('/source/my-ledger')
            ->assertSuccessful()
            ->assertSee('₹100,000.00', false)   // given
            ->assertSee('₹30,000.00', false)    // paid back
            ->assertSee('₹70,000.00', false)    // still outstanding
            ->assertDontSee('No money movement recorded yet');
    }

    /**
     * The dashboard's widgets are lazy-loaded, so a 200 on /source proves nothing
     * about them — they have to be rendered on their own to be checked.
     */
    public function test_the_dashboard_stats_show_the_sources_own_money(): void
    {
        $this->actingAs($this->source, 'source');

        Livewire::test(PortalStats::class)
            ->assertSuccessful()
            ->assertSee('₹100,000.00', false)
            ->assertSee('₹30,000.00', false)
            ->assertSee('₹70,000.00', false);
    }

    public function test_the_ipo_wise_widget_lists_the_ipos_funded_by_this_source(): void
    {
        $this->actingAs($this->source, 'source');

        Livewire::test(IpoWiseSummary::class)
            ->assertSuccessful()
            ->assertSee('Uncle A IPO')
            ->assertSee('₹15,000.00', false)   // applied
            ->assertSee('₹2,000.00', false)    // their half of the 4,000 profit
            ->assertDontSee('Nothing has been applied with your money yet');
    }

    public function test_the_applications_table_lists_only_this_sources_applications(): void
    {
        $sibling = $this->makeSourceWithData($this->admin, 'Brother B', '9111111111');

        $this->actingAs($this->source, 'source');

        Livewire::test(MyApplications::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($this->source->ipoApplications()->get())
            ->assertCanNotSeeTableRecords($sibling->ipoApplications()->get());
    }

    public function test_the_accounts_page_renders_the_accounts_the_money_went_into(): void
    {
        $this->actingAs($this->source, 'source');

        $this->get('/source/my-accounts')
            ->assertSuccessful()
            ->assertSee('Uncle A Bank')
            ->assertDontSee('Your money has not been placed into any account yet');
    }

    public function test_an_admin_can_switch_on_the_portal_and_set_a_password(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        $target = Source::create(['user_id' => $this->admin->id, 'name' => 'Cousin D']);

        Livewire::test(EditSource::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'mobile' => '+91 90000 22222',
                'portal_enabled' => true,
                'password' => 'letmein123',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();

        $this->assertSame('9000022222', $target->mobile);
        $this->assertTrue($target->portal_enabled);
        $this->assertTrue(Hash::check('letmein123', $target->password));
    }

    /** Editing a source for any other reason must not wipe the password they already have. */
    public function test_saving_a_source_without_typing_a_password_keeps_the_existing_one(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        $originalHash = $this->source->password;

        Livewire::test(EditSource::class, ['record' => $this->source->getRouteKey()])
            ->fillForm(['name' => 'Uncle A (renamed)', 'password' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->source->refresh();

        $this->assertSame('Uncle A (renamed)', $this->source->name);
        $this->assertSame($originalHash, $this->source->password);
        $this->assertTrue(Hash::check('secret123', $this->source->password));
    }

    public function test_mobile_numbers_are_stored_as_bare_ten_digits(): void
    {
        $source = Source::create([
            'user_id' => $this->admin->id,
            'name' => 'Friend C',
            'mobile' => '+91 90000-11111',
        ]);

        $this->assertSame('9000011111', $source->fresh()->mobile);
    }
}
