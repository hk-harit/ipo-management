<?php

namespace Tests\Feature;

use App\Filament\Pages\AccountOverviewPage;
use App\Filament\Pages\IpoDashboardPage;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\FundTransaction;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use App\Models\User;
use App\Services\AccountOverview;
use App\Services\SourceShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SourceShareTest extends TestCase
{
    use RefreshDatabase;

    private Source $utsav;

    private Ipo $jio;

    private Ipo $paras;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->utsav = Source::create(['name' => 'Utsav bhai', 'profit_share_percent' => 40, 'mobile' => '+91 98765 43210']);
        $account = Account::create(['name' => 'pilo', 'type' => 'bank', 'opening_balance' => 0]);
        $this->jio = Ipo::create(['name' => 'Jio Platforms', 'status' => 'open', 'min_amount' => 13428]);
        $this->paras = Ipo::create(['name' => 'Paras Healthcare', 'status' => 'listed', 'min_amount' => 14553]);

        FundTransaction::create(['source_id' => $this->utsav->id, 'account_id' => $account->id, 'type' => 'given', 'amount' => 400000, 'transaction_date' => '2026-10-01']);
        FundTransaction::create(['source_id' => $this->utsav->id, 'account_id' => $account->id, 'type' => 'returned', 'amount' => 25000, 'transaction_date' => '2026-10-02']);

        $make = fn (Ipo $ipo, string $name, float $amount, string $date) => IpoApplication::create([
            'ipo_id' => $ipo->id,
            'applicant_id' => Applicant::create(['name' => $name, 'relation' => 'friend', 'pan_number' => 'ABCDE1234F'])->id,
            'account_id' => $account->id, 'source_id' => $this->utsav->id, 'amount' => $amount,
            'applied_date' => $date, 'status' => 'applied', 'applicant_commission_percent' => 25,
        ]);

        $make($this->paras, 'Old Friend', 14553, '2026-10-10');
        $make($this->jio, 'Ved Mordiya', 13428, '2026-10-23');
        $make($this->jio, 'Namasvu', 187992, '2026-10-23');
    }

    private function share(): array
    {
        return (new AccountOverview)->build($this->jio->id)['source_groups']->firstWhere('source', 'Utsav bhai')['share'];
    }

    public function test_with_an_ipo_selected_the_share_is_about_that_ipo_only(): void
    {
        $share = $this->share();

        $this->assertSame(1, $share['ipo_count'], 'Jio only — Paras is not mentioned');
        $this->assertSame(2, $share['apps_total']);
        $this->assertSame(201420.0, $share['amount_total']);
        $this->assertSame('Jio Platforms', $share['focus_name']);
        $this->assertSame(400000.0, $share['given'], 'money given is the source\'s own balance, not per IPO');
        $this->assertSame(25000.0, $share['returned']);
        $this->assertSame(375000.0, $share['due']);
    }

    public function test_the_message_for_one_ipo_is_ready_for_whatsapp(): void
    {
        $text = $this->share()['text'];

        $this->assertStringContainsString('*IPO statement — Utsav bhai*', $text);
        $this->assertStringContainsString('You gave: ₹4,00,000', $text);
        $this->assertStringContainsString('Paid back: ₹25,000', $text);
        $this->assertStringContainsString('Still with us: ₹3,75,000', $text);
        $this->assertStringContainsString('*Jio Platforms — applied*', $text);
        $this->assertStringContainsString('Total 2 applications · ₹2,01,420', $text);
        $this->assertStringContainsString('1. Namasvu — 14 lots — ₹1,87,992', $text);
        $this->assertStringContainsString('2. Ved Mordiya — 1 lot — ₹13,428', $text);
        $this->assertStringContainsString('Total: ₹2,01,420', $text);

        $this->assertStringNotContainsString('Paras', $text, 'the other IPO must not leak into a Jio statement');
        $this->assertStringNotContainsString('Old Friend', $text);
        $this->assertStringNotContainsString('₹2,15,973', $text, 'no all-IPO total');
    }

    public function test_with_no_ipo_selected_every_ipo_is_summarised(): void
    {
        $source = Source::find($this->utsav->id);
        $apps = IpoApplication::query()->with(['ipo', 'applicant'])->where('source_id', $source->id)->get();

        $share = SourceShare::build($source, $apps, null, null);

        $this->assertSame(2, $share['ipo_count']);
        $this->assertSame(215973.0, $share['amount_total']);
        $this->assertStringContainsString('Total 2 IPOs · 3 applications · ₹2,15,973', $share['text']);
        $this->assertStringContainsString('• Jio Platforms — 2 applications · ₹2,01,420', $share['text']);
        $this->assertStringContainsString('• Paras Healthcare — 1 application · ₹14,553', $share['text']);
    }

    public function test_pan_is_on_the_image_but_not_in_the_text_and_commission_is_never_shared(): void
    {
        $share = $this->share();

        $this->assertStringNotContainsString('ABCDE1234F', $share['text']);
        $this->assertStringNotContainsString('25%', $share['text']);
        $this->assertStringNotContainsString('commission', strtolower($share['text']));

        $this->assertSame('ABCDE1234F', $share['focus_rows'][0]['pan'], 'the image card gets the PAN');

        $html = view('filament.pages.partials.source-share', ['share' => $share])->render();
        $this->assertStringContainsString('ABCDE1234F', $html);
        $this->assertStringContainsString('with PAN', $html);
        $this->assertStringNotContainsString('25%', $html);
        $this->assertStringNotContainsString('Paras Healthcare', $html, 'the image is for the selected IPO only');
    }

    public function test_the_whatsapp_number_is_built_from_the_sources_mobile(): void
    {
        $this->assertSame('919876543210', $this->share()['phone']);
        $this->assertSame('919876543210', SourceShare::whatsappNumber('98765 43210'));
        $this->assertNull(SourceShare::whatsappNumber(null));
        $this->assertNull(SourceShare::whatsappNumber('12345'));
    }

    public function test_money_is_written_in_indian_grouping(): void
    {
        $this->assertSame('₹14,52,086', SourceShare::inr(1452086));
        $this->assertSame('₹1,00,000', SourceShare::inr(100000));
        $this->assertSame('₹999', SourceShare::inr(999));
        $this->assertSame('−₹25,000', SourceShare::inr(-25000));
    }

    public function test_both_pages_show_the_share_buttons_and_the_image_card(): void
    {
        foreach ([IpoDashboardPage::class, AccountOverviewPage::class] as $page) {
            $component = Livewire::test($page);

            if ($page === IpoDashboardPage::class) {
                $component->call('openIpo', $this->jio->id);
            }

            $component
                ->assertOk()
                ->assertSee('WhatsApp message')
                ->assertSee('Copy text')
                ->assertSee('Copy image')
                ->assertSee('Download image')
                ->assertSee('Jio Platforms')
                ->assertSee('Share image');
        }
    }

    public function test_the_image_says_1_lot_not_1_lots(): void
    {
        $html = view('filament.pages.partials.source-share', ['share' => $this->share()])->render();

        $this->assertStringContainsString('14 lots', $html);
        $this->assertStringContainsString('1 lot<', $html);
        $this->assertStringNotContainsString('1 lots', $html);
    }

    public function test_a_group_without_a_source_has_no_share(): void
    {
        IpoApplication::query()->where('ipo_id', $this->jio->id)->update(['source_id' => null]);

        $groups = (new AccountOverview)->build($this->jio->id)['source_groups'];

        $this->assertNull($groups->firstWhere('source', 'No source')['share']);
    }
}
