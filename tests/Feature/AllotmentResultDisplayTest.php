<?php

namespace Tests\Feature;

use App\Filament\Pages\AllotmentEntryPage;
use App\Filament\Pages\IpoDashboardPage;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use App\Models\User;
use App\Services\AccountOverview;
use App\Services\SourceShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** "Ketla lagya": who got shares must be visible on the IPO Dashboard and in what a source is sent. */
class AllotmentResultDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Ipo $jio;

    private Source $utsav;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $account = Account::create(['name' => 'pilo', 'type' => 'bank', 'opening_balance' => 100000]);
        $this->utsav = Source::create(['name' => 'Utsav bhai', 'profit_share_percent' => 40]);
        $this->jio = Ipo::create(['name' => 'Jio Platforms', 'status' => 'closed', 'min_amount' => 13428, 'lot_size' => 12]);

        foreach (['Ved' => 'JQPPM7159J', 'Pratik' => 'OYSPK2271R', 'Isha' => 'CWUPD7942L', 'Nihar' => 'PSSPS7847N'] as $name => $pan) {
            IpoApplication::create([
                'ipo_id' => $this->jio->id,
                'applicant_id' => Applicant::create(['name' => $name, 'relation' => 'friend', 'pan_number' => $pan])->id,
                'account_id' => $account->id, 'source_id' => $this->utsav->id,
                'amount' => 13428, 'applied_date' => '2026-10-23', 'status' => 'applied',
            ]);
        }
    }

    /** Enter the result the way it is really done: paste the allotted PANs, rest not allotted. */
    private function enterResult(): void
    {
        Livewire::test(AllotmentEntryPage::class)
            ->set('paste', "JQPPM7159J 12\nCWUPD7942L 24")
            ->call('applyPaste')
            ->set('rows.'.IpoApplication::where('applicant_id', Applicant::where('name', 'Pratik')->value('id'))->value('id').'.result', 'refunded')
            ->call('save');
    }

    public function test_before_any_result_the_dashboard_says_so(): void
    {
        $report = Livewire::test(IpoDashboardPage::class)->call('openIpo', $this->jio->id)->instance()->report();

        $this->assertSame(0, $report['allotment']['allotted']);
        $this->assertSame(4, $report['allotment']['waiting']);

        Livewire::test(IpoDashboardPage::class)->call('openIpo', $this->jio->id)->assertSee('Allotment Entry');
    }

    public function test_the_ipo_dashboard_counts_and_names_who_got_it(): void
    {
        $this->enterResult();

        $allot = Livewire::test(IpoDashboardPage::class)->call('openIpo', $this->jio->id)->instance()->report()['allotment'];

        $this->assertSame(2, $allot['allotted']);
        $this->assertSame(1, $allot['not_allotted']);
        $this->assertSame(1, $allot['waiting']);
        $this->assertSame(36, $allot['allotted_shares'], '12 + 24');
        $this->assertSame(26856.0, $allot['allotted_amount']);
        $this->assertEqualsCanonicalizing(['Ved', 'Isha'], $allot['rows']->pluck('applicant')->all());
        $this->assertSame('Utsav bhai', $allot['rows']->first()['source_name']);

        Livewire::test(IpoDashboardPage::class)
            ->call('openIpo', $this->jio->id)
            ->assertSee('લાગ્યા')
            ->assertSee('36 shares')
            ->assertSee('Ved')
            ->assertSee('Isha');
    }

    public function test_the_whatsapp_text_marks_who_got_it_and_who_did_not(): void
    {
        $this->enterResult();

        $share = (new AccountOverview)->build($this->jio->id)['source_groups']->firstWhere('source', 'Utsav bhai')['share'];

        $this->assertSame(2, $share['allotted']);
        $this->assertSame(1, $share['not_allotted']);
        $this->assertSame(1, $share['waiting']);

        $text = $share['text'];
        $this->assertStringContainsString('લાગ્યા: 2 · ન લાગ્યા: 1 · બાકી: 1', $text);
        $this->assertMatchesRegularExpression('/Ved — 1 લોટ — ₹13,428 ✅ લાગ્યા \(12 શેર\)/u', $text);
        $this->assertMatchesRegularExpression('/Isha — 1 લોટ — ₹13,428 ✅ લાગ્યા \(24 શેર\)/u', $text);
        $this->assertMatchesRegularExpression('/Pratik — 1 લોટ — ₹13,428 ❌ ન લાગ્યા/u', $text);
        $this->assertDoesNotMatchRegularExpression('/Nihar[^\n]*(✅|❌)/u', $text, 'a result that is not in yet gets no mark');
    }

    public function test_the_image_card_shows_the_result_too(): void
    {
        $this->enterResult();
        $share = (new AccountOverview)->build($this->jio->id)['source_groups']->firstWhere('source', 'Utsav bhai')['share'];

        $html = view('filament.pages.partials.source-share', ['share' => $share])->render();

        $this->assertStringContainsString('લાગ્યા: 2', $html);
        $this->assertStringContainsString('ન લાગ્યા: 1', $html);
        $this->assertStringContainsString('✅ લાગ્યા (12 શેર)', $html);
        $this->assertStringContainsString('❌ ન લાગ્યા', $html);
    }

    public function test_the_source_table_on_the_page_shows_each_applicants_result_and_the_counts(): void
    {
        $this->enterResult();

        $groups = (new AccountOverview)->build($this->jio->id)['source_groups'];
        $utsav = $groups->firstWhere('source', 'Utsav bhai');

        $this->assertSame([2, 1, 1], [$utsav['allotted'], $utsav['not_allotted'], $utsav['waiting']]);
        $this->assertSame(24, $utsav['rows']->firstWhere('applicant', 'Isha')['shares']);

        Livewire::test(IpoDashboardPage::class)
            ->call('openIpo', $this->jio->id)
            ->assertSee('Result (લાગ્યા?)')
            ->assertSee('લાગ્યા 2')
            ->assertSee('ન લાગ્યા 1')
            ->assertSee('✅ લાગ્યા (24 શેર)')
            ->assertSee('❌ ન લાગ્યા');
    }

    public function test_the_whatsapp_buttons_have_their_own_background_colour(): void
    {
        $this->enterResult();
        $share = (new AccountOverview)->build($this->jio->id)['source_groups']->firstWhere('source', 'Utsav bhai')['share'];

        $html = view('filament.pages.partials.source-share', ['share' => $share])->render();

        $this->assertMatchesRegularExpression('/<a[^>]*style="background:#16a34a; color:#ffffff;"[^>]*>\s*WhatsApp message/s', $html);
    }

    public function test_the_result_marks_follow_the_status(): void
    {
        $this->assertSame('✅ લાગ્યા (12 શેર)', SourceShare::resultMark('allotted', 12));
        $this->assertSame('✅ લાગ્યા', SourceShare::resultMark('sold', null));
        $this->assertSame('❌ ન લાગ્યા', SourceShare::resultMark('refunded', null));
        $this->assertSame('', SourceShare::resultMark('applied', null));
    }
}
