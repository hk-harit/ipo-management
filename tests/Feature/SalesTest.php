<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Applicant;
use App\Models\ApplicationSale;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use App\Models\User;
use App\Services\DashboardSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Applicant $applicant;

    private Ipo $ipo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->account = Account::create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 10000]);
        $this->applicant = Applicant::create(['name' => 'Friend', 'relation' => 'friend']);
        $this->ipo = Ipo::create(['name' => 'Test IPO', 'status' => 'open']);
    }

    private function allotted(float $amount = 3000, int $shares = 10, array $extra = []): IpoApplication
    {
        return IpoApplication::create(array_merge([
            'ipo_id' => $this->ipo->id,
            'applicant_id' => $this->applicant->id,
            'account_id' => $this->account->id,
            'amount' => $amount,
            'applied_date' => '2026-10-20',
            'status' => 'allotted',
            'shares_allotted' => $shares,
        ], $extra));
    }

    private function sell(IpoApplication $app, int $shares, float $price, float $charges = 0, string $date = '2026-10-30'): ApplicationSale
    {
        return $app->sales()->create([
            'sale_date' => $date,
            'shares_sold' => $shares,
            'price_per_share' => $price,
            'charges' => $charges,
        ]);
    }

    public function test_a_sale_computes_gross_and_net_after_charges(): void
    {
        $sale = $this->sell($this->allotted(), 4, 400, 20);

        $this->assertSame('1600.00', (string) $sale->gross_amount);
        $this->assertSame('1580.00', (string) $sale->net_amount);
    }

    public function test_a_partial_sale_credits_the_account_and_keeps_the_rest_in_demat(): void
    {
        $app = $this->allotted();
        $this->sell($app, 4, 400, 20);
        $app->refresh();

        $this->assertSame('allotted', $app->status, 'not sold until every share is gone');
        $this->assertSame(4, (int) $app->shares_sold);
        $this->assertSame(6, $app->shares_remaining);
        $this->assertNull($app->profit, 'profit is only realised once fully sold');
        $this->assertSame(10000 - 3000 + 1580.0, $this->account->current_balance);
    }

    public function test_selling_every_share_marks_it_sold_with_profit_after_charges(): void
    {
        $app = $this->allotted();
        $this->sell($app, 4, 400, 20);
        $this->sell($app, 6, 420, 25, '2026-11-02');
        $app->refresh();

        $this->assertSame('sold', $app->status);
        $this->assertSame('2026-11-02', $app->sold_date->toDateString());
        $this->assertSame(4075.0, $app->total_received); // 1580 + 2495
        $this->assertSame(1075.0, $app->profit);
        $this->assertSame(45.0, $app->sales_charges);
        $this->assertSame(10000 - 3000 + 4075.0, $this->account->current_balance);
    }

    public function test_the_profit_split_runs_on_the_profit_after_charges(): void
    {
        $source = Source::create(['name' => 'Uncle', 'profit_share_percent' => 50]);
        $app = $this->allotted(3000, 10, ['source_id' => $source->id, 'applicant_commission_percent' => 10]);
        $this->sell($app, 10, 400, 100); // net 3900, profit 900
        $app->refresh();

        $this->assertSame(900.0, $app->profit);
        $this->assertSame(450.0, $app->source_profit_share);
        $this->assertSame(90.0, $app->applicant_profit_share);
        $this->assertSame(360.0, $app->your_profit_share);
    }

    public function test_you_cannot_sell_more_than_was_allotted(): void
    {
        $app = $this->allotted(3000, 10);
        $this->sell($app, 8, 400);

        try {
            $this->sell($app, 3, 400);
            $this->fail('selling 11 of 10 shares must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shares_sold', $e->errors());
        }

        $this->assertSame(1, $app->sales()->count());
    }

    public function test_deleting_the_last_sale_puts_the_application_back_in_demat(): void
    {
        $app = $this->allotted();
        $sale = $this->sell($app, 10, 400);
        $this->assertSame('sold', $app->fresh()->status);

        $sale->delete();
        $app->refresh();

        $this->assertSame('allotted', $app->status);
        $this->assertNull($app->shares_sold);
        $this->assertNull($app->sold_date);
        $this->assertSame(7000.0, $this->account->current_balance);
    }

    public function test_a_partly_allotted_application_counts_its_refund_and_its_sales(): void
    {
        // Applied 6000, 5 shares allotted, 2000 refunded; sold for 5 x 700.
        $app = $this->allotted(6000, 5, ['status' => 'partial_allotted', 'return_amount' => 2000, 'return_date' => '2026-10-25']);
        $this->sell($app, 5, 700, 50);
        $app->refresh();

        $this->assertSame('sold', $app->status);
        $this->assertSame(2000.0, (float) $app->return_amount, 'the refund is untouched');
        $this->assertSame(3450.0, $app->sales_net);
        $this->assertSame(5450.0, $app->total_received);
        $this->assertSame(-550.0, $app->profit);
        $this->assertSame(10000 - 6000 + 2000 + 3450.0, $this->account->current_balance);
    }

    public function test_an_old_style_sale_without_sales_rows_still_works(): void
    {
        $app = $this->allotted(3000, 10, ['status' => 'sold', 'return_amount' => 4000, 'return_date' => '2026-10-30']);

        $this->assertSame(1000.0, $app->profit);
        $this->assertSame(11000.0, $this->account->current_balance);
    }

    public function test_the_passbook_lists_each_sale_and_still_ends_on_the_balance(): void
    {
        $app = $this->allotted(6000, 5, ['status' => 'partial_allotted', 'return_amount' => 2000, 'return_date' => '2026-10-25']);
        $this->sell($app, 2, 700, 30, '2026-10-29');
        $this->sell($app, 3, 710, 40, '2026-10-31');

        $ledger = $this->account->buildLedger();

        $this->assertSame(2, $ledger->filter(fn ($e) => str_starts_with($e['description'], 'Sold '))->count());
        $this->assertTrue($ledger->contains(fn ($e) => str_starts_with($e['description'], 'Partial Refund')));
        $this->assertEqualsWithDelta($this->account->current_balance, (float) $ledger->last()['balance'], 0.001);
    }

    public function test_dashboard_figures_agree_with_the_account_with_sales_in_play(): void
    {
        $partlySold = $this->allotted(6000, 10);
        $this->sell($partlySold, 4, 700, 30);
        $fullySold = $this->allotted(3000, 10);
        $this->sell($fullySold, 10, 400, 20, now()->toDateString());

        $summary = new DashboardSummary;
        $figures = $summary->accountFigures()[$this->account->id];

        $this->assertEqualsWithDelta($this->account->current_balance, $figures['balance'], 0.001);
        // 6000 cost, 4 of 10 shares sold -> 60% (3600) is still invested.
        $this->assertEqualsWithDelta(3600.0, $figures['invested'], 0.001);

        $profit = $summary->profit(...DashboardSummary::periodRange('today'));
        $this->assertSame(1, $profit['count']);
        $this->assertSame(980.0, $profit['profit']);
    }
}
