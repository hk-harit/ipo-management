<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_public_maintenance_urls_are_gone(): void
    {
        $this->get('/migrate')->assertNotFound();
        $this->get('/clear-cache')->assertNotFound();
    }

    public function test_maintenance_urls_reject_guests_and_non_admins(): void
    {
        $this->get('/maintenance/clear-cache')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get('/maintenance/clear-cache')
            ->assertForbidden();
    }

    public function test_maintenance_urls_work_for_an_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/maintenance/clear-cache')
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    public function test_cron_urls_are_closed_when_no_token_is_configured(): void
    {
        config(['services.cron.token' => null]);

        $this->get('/cron/sync-ipos')->assertForbidden();
        $this->get('/cron/sync-ipos?token=')->assertForbidden();
        $this->get('/cron/sync-ipos?token=ipo_live_sync_2026')->assertForbidden();
    }

    public function test_cron_urls_reject_a_wrong_token_and_accept_the_right_one(): void
    {
        config(['services.cron.token' => 'secret-token']);

        $this->get('/cron/check-allotments?token=wrong')->assertForbidden();
        $this->get('/cron/check-allotments')->assertForbidden();
        $this->get('/cron/check-allotments?token=secret-token')->assertOk();
        $this->withHeader('X-Cron-Token', 'secret-token')->get('/cron/check-allotments')->assertOk();
    }

    public function test_only_admins_can_open_manage_logins(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get('/admin/users')
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/users')
            ->assertOk();
    }
}
