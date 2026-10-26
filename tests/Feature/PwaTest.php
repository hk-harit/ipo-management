<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Source\Pages\Auth\SourceLogin;
use Livewire\Livewire;
use Tests\TestCase;

/** The "install it as a phone app" setup: manifest, icons, service worker, stay signed in. */
class PwaTest extends TestCase
{
    private function manifest(string $file): array
    {
        $json = json_decode(file_get_contents(public_path($file)), true);
        $this->assertIsArray($json, "$file must be valid JSON");

        return $json;
    }

    public function test_both_manifests_are_installable(): void
    {
        foreach (['manifest.webmanifest' => '/admin', 'source-manifest.webmanifest' => '/source'] as $file => $start) {
            $m = $this->manifest($file);

            $this->assertSame($start, $m['start_url']);
            $this->assertSame('standalone', $m['display']);
            $this->assertNotEmpty($m['name']);
            $this->assertNotEmpty($m['short_name']);
            $this->assertSame('#059669', $m['theme_color']);

            $sizes = collect($m['icons'])->pluck('sizes')->all();
            $this->assertContains('192x192', $sizes);
            $this->assertContains('512x512', $sizes);
            $this->assertContains('maskable', collect($m['icons'])->pluck('purpose')->all());
        }
    }

    public function test_every_icon_in_the_manifests_exists_at_its_stated_size(): void
    {
        foreach (['manifest.webmanifest', 'source-manifest.webmanifest'] as $file) {
            foreach ($this->manifest($file)['icons'] as $icon) {
                $path = public_path(ltrim($icon['src'], '/'));
                $this->assertFileExists($path);

                [$w, $h] = getimagesize($path);
                $this->assertSame($icon['sizes'], "{$w}x{$h}", $icon['src']);
            }
        }

        [$w, $h] = getimagesize(public_path('icons/apple-touch-icon.png'));
        $this->assertSame([180, 180], [$w, $h]);
    }

    public function test_the_service_worker_never_serves_a_page_from_its_cache(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("addEventListener('fetch'", $sw);
        $this->assertStringContainsString("request.mode === 'navigate'", $sw);
        $this->assertStringContainsString('fetch(request).catch(() => caches.match(OFFLINE_URL))', $sw, 'pages: network first, offline notice only on failure');
        $this->assertStringContainsString("startsWith('/livewire')", $sw, 'Livewire calls are left alone');
        $this->assertStringContainsString("request.method !== 'GET'", $sw);
        $this->assertFileExists(public_path('offline.html'));
    }

    public function test_the_admin_login_page_links_the_manifest_and_registers_the_worker(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('manifest.webmanifest', false)
            ->assertDontSee('source-manifest', false)
            ->assertSee('apple-touch-icon', false)
            ->assertSee('serviceWorker.register', false);
    }

    // Own test: Filament keeps a panel's render hooks for the rest of the process, so two panels
    // in one test would see each other's. In real use every request is its own process.
    public function test_the_source_login_page_links_its_own_manifest(): void
    {
        $this->get('/source/login')->assertOk()
            ->assertSee('source-manifest.webmanifest', false)
            ->assertSee('serviceWorker.register', false);
    }

    public function test_the_login_forms_start_with_keep_me_signed_in_ticked(): void
    {
        Livewire::test(Login::class)->assertFormSet(['remember' => true]);
        Livewire::test(SourceLogin::class)->assertFormSet(['remember' => true]);
    }

    public function test_sessions_last_thirty_days_by_default(): void
    {
        $this->assertStringContainsString("env('SESSION_LIFETIME', 43200)", file_get_contents(base_path('config/session.php')));
    }
}
