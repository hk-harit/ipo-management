<?php

namespace Tests\Feature;

use App\Filament\Pages\AllotmentEntryPage;
use App\Filament\Widgets\AllottedAwaitingSaleWidget;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AllotmentEntryTest extends TestCase
{
    use RefreshDatabase;

    private Ipo $jio;

    private Account $account;

    /** @var array<string, IpoApplication> */
    private array $apps = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->account = Account::create(['name' => 'pilo', 'type' => 'bank', 'opening_balance' => 100000]);
        $this->jio = Ipo::create(['name' => 'Jio Platforms', 'status' => 'closed', 'min_amount' => 13428, 'lot_size' => 12]);

        foreach (['Ved' => 'JQPPM7159J', 'Pratik' => 'OYSPK2271R', 'Isha' => 'CWUPD7942L'] as $name => $pan) {
            $this->apps[$name] = IpoApplication::create([
                'ipo_id' => $this->jio->id,
                'applicant_id' => Applicant::create(['name' => $name, 'relation' => 'friend', 'pan_number' => $pan])->id,
                'account_id' => $this->account->id,
                'amount' => 13428,
                'applied_date' => '2026-10-23',
                'status' => 'applied',
            ]);
        }
    }

    private function page()
    {
        return Livewire::test(AllotmentEntryPage::class);
    }

    public function test_a_pan_can_carry_its_own_share_count(): void
    {
        $this->page()
            ->set('paste', "JQPPM7159J 24\nOYSPK2271R, Allotted, 12\nCWUPD7942L")
            ->call('applyPaste')
            ->assertSet('rows.'.$this->apps['Ved']->id.'.shares', 24)
            ->assertSet('rows.'.$this->apps['Pratik']->id.'.shares', 12)
            ->assertSet('rows.'.$this->apps['Isha']->id.'.shares', 12, 'no count given: one lot')
            ->assertSet('rows.'.$this->apps['Isha']->id.'.result', 'allotted');
    }

    public function test_the_page_opens_with_the_latest_ipo_and_lists_pending_applications(): void
    {
        $this->page()
            ->assertOk()
            ->assertSet('ipo', (string) $this->jio->id)
            ->assertSee('Ved')
            ->assertSee('JQPPM7159J')
            ->assertSee('Pratik')
            ->assertSee('Isha')
            ->assertSet('rows.'.$this->apps['Ved']->id.'.result', 'pending');

        $this->get('/admin/allotment-entry')->assertOk();
    }

    public function test_pasting_pans_marks_those_applications_allotted_with_one_lots_shares(): void
    {
        $page = $this->page()
            ->set('paste', "jqppm7159j\nCWUPD7942L, ZZZZZ9999Z")
            ->call('applyPaste');

        $page->assertSet('rows.'.$this->apps['Ved']->id.'.result', 'allotted')
            ->assertSet('rows.'.$this->apps['Ved']->id.'.shares', 12)
            ->assertSet('rows.'.$this->apps['Isha']->id.'.result', 'allotted')
            ->assertSet('rows.'.$this->apps['Pratik']->id.'.result', 'pending')
            ->assertNotified('2 PAN allotted marks thaya');
    }

    public function test_the_rest_can_be_marked_not_allotted_in_one_click(): void
    {
        $this->page()
            ->set('paste', 'JQPPM7159J')
            ->call('applyPaste')
            ->call('markRestNotAllotted')
            ->assertSet('rows.'.$this->apps['Ved']->id.'.result', 'allotted')
            ->assertSet('rows.'.$this->apps['Pratik']->id.'.result', 'not_allotted')
            ->assertSet('rows.'.$this->apps['Isha']->id.'.result', 'not_allotted');
    }

    public function test_saving_records_allotment_and_not_allotted_and_the_names_show_in_the_awaiting_sale_list(): void
    {
        $before = $this->account->current_balance; // 100000 - 3 x 13428

        $this->page()
            ->set('paste', 'JQPPM7159J')
            ->call('applyPaste')
            ->call('markRestNotAllotted')
            ->call('save')
            ->assertNotified('Allotment save thayu');

        $ved = $this->apps['Ved']->fresh();
        $this->assertSame('allotted', $ved->status);
        $this->assertSame(12, (int) $ved->shares_allotted);
        $this->assertNull($ved->return_amount);

        foreach (['Pratik', 'Isha'] as $name) {
            $notAllotted = $this->apps[$name]->fresh();
            $this->assertSame('not_allotted', $notAllotted->status, 'no shares, refund still to come');
            $this->assertNull($notAllotted->return_amount, 'the money has not come back yet');
            $this->assertNull($notAllotted->return_date);
        }

        $this->assertSame($before, $this->account->current_balance, 'nothing is credited until the refund arrives');
        $this->assertSame(2 * 13428.0, $this->account->blocked_in_ipos, 'the two not-allotted amounts are still held by the bank');
        $this->assertSame(13428.0, $this->account->invested_in_ipos);

        // The point of it all: the allottee's name now shows in the "Allotted — Awaiting Sale" list.
        Livewire::test(AllottedAwaitingSaleWidget::class)
            ->assertSee('Ved')
            ->assertCanSeeTableRecords([$ved])
            ->assertCanNotSeeTableRecords([$this->apps['Pratik']->fresh()]);
    }

    public function test_an_allotted_application_needs_its_shares_before_it_saves(): void
    {
        $page = $this->page()
            ->set('rows.'.$this->apps['Ved']->id.'.result', 'allotted')
            ->set('rows.'.$this->apps['Ved']->id.'.shares', null)
            ->call('save')
            ->assertNotified('Pehla shares bharo');

        $this->assertSame('applied', $this->apps['Ved']->fresh()->status, 'nothing was saved');
        $this->assertNotNull($page);
    }

    public function test_a_wrong_entry_can_be_put_back_to_pending(): void
    {
        $this->page()->set('paste', 'JQPPM7159J')->call('applyPaste')->call('save');
        $this->assertSame('allotted', $this->apps['Ved']->fresh()->status);

        $this->page()
            ->assertSet('rows.'.$this->apps['Ved']->id.'.result', 'allotted')
            ->set('rows.'.$this->apps['Ved']->id.'.result', 'pending')
            ->call('save');

        $ved = $this->apps['Ved']->fresh();
        $this->assertSame('applied', $ved->status);
        $this->assertNull($ved->shares_allotted);
    }

    public function test_sold_applications_are_not_offered_and_a_login_sees_only_its_own(): void
    {
        $this->apps['Isha']->update(['status' => 'sold', 'return_amount' => 15000, 'return_date' => '2026-10-30']);

        $this->page()->assertDontSee('Isha')->assertSee('Ved');

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->page()->assertSet('ipo', '')->assertSee('Aa IPO ma result nakhva jevi koi application nathi');
    }
}
