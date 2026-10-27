<?php

namespace App\Filament\Pages;

use App\Models\IpoApplication;
use App\Services\AccountOverview;
use App\Services\SourceShare;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One screen for selling the allotted shares after listing.
 *
 * Selling happens in two steps: the sell order is first placed (about 9:00 - 9:30, before the
 * market opens) and it executes later (about 10:00). Every allotted application therefore sits
 * in one of three stages: still to request, request sent (waiting to sell), or sold. The page
 * lists them account by account so nothing is missed when there are many accounts.
 */
class SellDeskPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'IPO Management';

    protected static ?string $navigationLabel = 'Sell Desk';

    protected static ?string $title = 'Sell Desk';

    protected static ?string $slug = 'sell-desk';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.sell-desk';

    public const REQUEST = 'request';

    public const WAITING = 'waiting';

    public const SOLD = 'sold';

    /** 'all' or an IPO id. */
    public string $ipo = 'all';

    /** The stage on show: request | waiting | sold. */
    public string $stage = self::REQUEST;

    /** Sale price per share used by the "sold" buttons. */
    public string $price = '';

    /** Everything it costs to sell one application's lot (brokerage, DP charge). */
    public string $charges = '';

    /** application id => price, when one lot sold at a different price. */
    public array $prices = [];

    public function getSubheading(): ?string
    {
        return 'After listing: first send the sell request (9:00 - 9:30), then mark it sold once it sells (about 10:00).';
    }

    public function ipoOptions(): Collection
    {
        return (new AccountOverview)->ipoOptions();
    }

    private function base()
    {
        return IpoApplication::query()
            ->whereIn('status', IpoApplication::ALLOTTED)
            ->where('shares_allotted', '>', 0)
            ->with(['applicant:id,name,pan_number', 'ipo:id,name', 'account:id,name', 'subAccount:id,name'])
            ->when($this->ipo !== 'all', fn ($q) => $q->where('ipo_id', (int) $this->ipo));
    }

    public static function stageOf(IpoApplication $a): string
    {
        if ($a->status === 'sold' || $a->shares_remaining === 0) {
            return self::SOLD;
        }

        return $a->sell_requested_at ? self::WAITING : self::REQUEST;
    }

    private function effectiveAccountId(IpoApplication $a): int
    {
        return (int) ($a->sub_account_id ?: $a->account_id);
    }

    private function effectiveAccountName(IpoApplication $a): string
    {
        return ($a->sub_account_id ? $a->subAccount?->name : $a->account?->name) ?? 'Account #'.$this->effectiveAccountId($a);
    }

    /** Every allotted application of the chosen IPO(s). */
    private function allotted(): Collection
    {
        return $this->base()->orderBy('applicant_id')->get();
    }

    /** @return array{request: array, waiting: array, sold: array} shares and applications per stage */
    public function counts(): array
    {
        $all = $this->allotted();
        $out = [];

        foreach ([self::REQUEST, self::WAITING, self::SOLD] as $stage) {
            $rows = $all->filter(fn (IpoApplication $a) => self::stageOf($a) === $stage);
            $out[$stage] = [
                'apps' => $rows->count(),
                'shares' => (int) $rows->sum(fn (IpoApplication $a) => $stage === self::SOLD ? (int) $a->shares_sold : $a->shares_remaining),
                'accounts' => $rows->map(fn (IpoApplication $a) => $this->effectiveAccountId($a))->unique()->count(),
            ];
        }

        return $out;
    }

    /**
     * The applications of the chosen stage, grouped by the demat account they sit in.
     *
     * @return Collection<int, array{account_id: int, account: string, shares: int, count: int, rows: Collection}>
     */
    public function groups(): Collection
    {
        return $this->allotted()
            ->filter(fn (IpoApplication $a) => self::stageOf($a) === $this->stage)
            ->groupBy(fn (IpoApplication $a) => $this->effectiveAccountId($a))
            ->map(fn (Collection $apps, $accountId) => [
                'account_id' => (int) $accountId,
                'account' => $this->effectiveAccountName($apps->first()),
                'shares' => (int) $apps->sum(fn (IpoApplication $a) => self::stageOf($a) === self::SOLD ? (int) $a->shares_sold : $a->shares_remaining),
                'count' => $apps->count(),
                'rows' => $apps->values(),
            ])
            ->sortBy('account', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function showStage(string $stage): void
    {
        $this->stage = in_array($stage, [self::REQUEST, self::WAITING, self::SOLD], true) ? $stage : self::REQUEST;
    }

    // ---- step 1: the sell request ----

    public function markRequested(int $id): void
    {
        $application = $this->base()->find($id);

        if ($application && self::stageOf($application) === self::REQUEST) {
            $application->update(['sell_requested_at' => now()]);
        }
    }

    public function undoRequested(int $id): void
    {
        $application = $this->base()->find($id);

        if ($application && self::stageOf($application) === self::WAITING) {
            $application->update(['sell_requested_at' => null]);
        }
    }

    /** The sell request for every application of one account has been sent. */
    public function requestAccount(int $accountId): void
    {
        $count = $this->requestWhere(fn (IpoApplication $a) => $this->effectiveAccountId($a) === $accountId);

        Notification::make()->success()->title("{$count} sell request(s) marked as sent")->send();
    }

    /** Every application still to request (of the chosen IPO) has been sent. */
    public function requestAll(): void
    {
        $count = $this->requestWhere(fn () => true);

        Notification::make()->success()->title("{$count} sell request(s) marked as sent")->send();
    }

    private function requestWhere(callable $filter): int
    {
        $count = 0;

        foreach ($this->allotted()->filter(fn (IpoApplication $a) => self::stageOf($a) === self::REQUEST) as $application) {
            if ($filter($application)) {
                $application->update(['sell_requested_at' => now()]);
                $count++;
            }
        }

        return $count;
    }

    // ---- step 2: it sold ----

    private function priceFor(IpoApplication $application): float
    {
        $typed = $this->prices[$application->id] ?? null;

        return round((float) (($typed === null || $typed === '') ? $this->price : $typed), 2);
    }

    /** Sells everything left on the application at the given price. False when it cannot. */
    private function sellOut(IpoApplication $application): bool
    {
        $price = $this->priceFor($application);

        if ($price <= 0 || $application->shares_remaining <= 0) {
            return false;
        }

        $application->sales()->create([
            'sale_date' => now()->toDateString(),
            'shares_sold' => $application->shares_remaining,
            'price_per_share' => $price,
            'charges' => max(0.0, (float) $this->charges),
        ]);

        unset($this->prices[$application->id]);

        return true;
    }

    /** One application sold (all its remaining shares). */
    public function markSold(int $id): void
    {
        $application = $this->base()->find($id);

        if (! $application || ! $this->sellOut($application)) {
            Notification::make()->warning()->title('Enter the sale price first')->body('Type the price per share at the top (or in the row).')->send();

            return;
        }

        $application->refresh();

        Notification::make()->success()
            ->title('Sold — '.SourceShare::inr((float) $application->sales()->latest('id')->value('net_amount')).' credited')
            ->body($application->applicant?->name.' · '.$this->effectiveAccountName($application))
            ->send();
    }

    /** Every request already sent in one account has sold, all at the price typed at the top. */
    public function soldAccount(int $accountId): void
    {
        if ((float) $this->price <= 0) {
            Notification::make()->warning()->title('Enter the sale price first')->body('Type the price per share at the top.')->send();

            return;
        }

        $count = 0;
        $net = 0.0;

        DB::transaction(function () use ($accountId, &$count, &$net) {
            foreach ($this->allotted()->filter(fn (IpoApplication $a) => self::stageOf($a) === self::WAITING) as $application) {
                if ($this->effectiveAccountId($application) === $accountId && $this->sellOut($application)) {
                    $count++;
                    $net += (float) $application->sales()->latest('id')->value('net_amount');
                }
            }
        });

        Notification::make()->success()
            ->title("{$count} application(s) sold")
            ->body(SourceShare::inr($net).' credited to the account.')
            ->send();
    }
}
