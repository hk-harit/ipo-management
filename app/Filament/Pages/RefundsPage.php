<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\IpoApplication;
use App\Services\AccountOverview;
use App\Services\SourceShare;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where the refunds of "not allotted" applications are due, and whether they have arrived.
 *
 * An application that did not get shares stays blocked in its account until the bank refunds
 * it. Here every such refund is listed under the account it will land in; marking one as
 * arrived credits the account (status becomes Refunded). The amount can be edited when the
 * bank returned slightly less, e.g. ₹14,905 on a ₹15,000 application.
 */
class RefundsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationGroup = 'IPO Management';

    protected static ?string $navigationLabel = 'Refunds (રિફંડ)';

    protected static ?string $title = 'Refunds (રિફંડ)';

    protected static ?string $slug = 'refunds';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.refunds';

    /** 'all' or an IPO id. */
    public string $ipo = 'all';

    /** The day the refunds arrived (defaults to today). */
    public string $date = '';

    /** application id => amount that actually came back (blank = the full amount). */
    public array $amounts = [];

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function getSubheading(): ?string
    {
        return 'Je application ma "ન લાગ્યા" aavyu tenu refund kya account ma aavshe, ane aavi gayu ke nahi.';
    }

    public function ipoOptions(): Collection
    {
        return (new AccountOverview)->ipoOptions();
    }

    private function base()
    {
        return IpoApplication::query()
            ->with(['applicant:id,name,pan_number', 'ipo:id,name,allotment_date', 'account:id,name', 'subAccount:id,name'])
            ->when($this->ipo !== 'all', fn ($q) => $q->where('ipo_id', (int) $this->ipo));
    }

    private function effectiveAccountId(IpoApplication $a): int
    {
        return (int) ($a->sub_account_id ?: $a->account_id);
    }

    private function effectiveAccountName(IpoApplication $a): string
    {
        return ($a->sub_account_id ? $a->subAccount?->name : $a->account?->name) ?? 'Account #'.$this->effectiveAccountId($a);
    }

    /**
     * Refunds still to come, grouped by the account they land in, biggest first.
     *
     * @return Collection<int, array{account_id: int, account: string, total: float, count: int, rows: Collection}>
     */
    public function pending(): Collection
    {
        return $this->base()
            ->where('status', 'not_allotted')
            ->orderBy('applicant_id')
            ->get()
            ->groupBy(fn (IpoApplication $a) => $this->effectiveAccountId($a))
            ->map(fn (Collection $apps, $accountId) => [
                'account_id' => (int) $accountId,
                'account' => $this->effectiveAccountName($apps->first()),
                'total' => (float) $apps->sum('amount'),
                'count' => $apps->count(),
                'rows' => $apps->values(),
            ])
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Refunds that have arrived in the last 30 days, newest first, grouped by account.
     *
     * @return Collection<int, array{account: string, total: float, count: int, rows: Collection}>
     */
    public function received(): Collection
    {
        return $this->base()
            ->where('status', 'refunded')
            ->whereNotNull('return_amount')
            ->where('return_date', '>=', now()->subDays(30)->toDateString())
            ->orderByDesc('return_date')
            ->get()
            ->groupBy(fn (IpoApplication $a) => $this->effectiveAccountId($a))
            ->map(fn (Collection $apps) => [
                'account' => $this->effectiveAccountName($apps->first()),
                'total' => (float) $apps->sum('return_amount'),
                'count' => $apps->count(),
                'rows' => $apps->values(),
            ])
            ->sortByDesc('total')
            ->values();
    }

    /** What comes back for this application: the typed amount, or the full amount; never more than was applied. */
    private function refundAmountFor(IpoApplication $application): float
    {
        $typed = $this->amounts[$application->id] ?? null;
        $amount = ($typed === null || $typed === '') ? (float) $application->amount : (float) $typed;

        return round(min(max($amount, 0.0), (float) $application->amount), 2);
    }

    private function markArrived(IpoApplication $application): bool
    {
        $amount = $this->refundAmountFor($application);

        if ($amount <= 0 || $application->status !== 'not_allotted') {
            return false;
        }

        $application->update([
            'status' => 'refunded',
            'return_amount' => $amount,
            'return_date' => $this->date ?: now()->toDateString(),
            'shares_allotted' => 0,
        ]);

        unset($this->amounts[$application->id]);

        return true;
    }

    /** One refund has arrived. */
    public function markReceived(int $id): void
    {
        $application = $this->base()->where('status', 'not_allotted')->find($id);

        if (! $application || ! $this->markArrived($application)) {
            Notification::make()->warning()->title('Refund mark na thayu')->body('Amount sachi nathi, ke aa pehla thi mark thai gayu chhe.')->send();

            return;
        }

        Notification::make()->success()
            ->title(SourceShare::inr((float) $application->fresh()->return_amount).' '.$this->effectiveAccountName($application).' ma aavi gayu')
            ->body($application->applicant?->name)
            ->send();
    }

    /** Every pending refund of one account has arrived. */
    public function markAccountReceived(int $accountId): void
    {
        $count = 0;
        $total = 0.0;

        DB::transaction(function () use ($accountId, &$count, &$total) {
            foreach ($this->base()->where('status', 'not_allotted')->get() as $application) {
                if ($this->effectiveAccountId($application) === $accountId && $this->markArrived($application)) {
                    $count++;
                    $total += (float) $application->fresh()->return_amount;
                }
            }
        });

        $name = Account::find($accountId)?->name ?? 'account';

        Notification::make()->success()
            ->title("{$name}: {$count} refund aavi gaya")
            ->body(SourceShare::inr($total).' account ma jama thayu.')
            ->send();
    }

    /** Put a refund back to "still to come" (marked by mistake). */
    public function undoReceived(int $id): void
    {
        $application = $this->base()->where('status', 'refunded')->find($id);

        if (! $application) {
            return;
        }

        $application->update(['status' => 'not_allotted', 'return_amount' => null, 'return_date' => null]);

        Notification::make()->warning()->title('Pachu "refund baki" kari didhu')->body($application->applicant?->name)->send();
    }

    /** Totals for the cards. */
    public function totals(): array
    {
        $pending = $this->base()->where('status', 'not_allotted');
        $received = $this->base()->where('status', 'refunded')->whereNotNull('return_amount')->where('return_date', '>=', now()->subDays(30)->toDateString());

        return [
            'pending_count' => (clone $pending)->count(),
            'pending_amount' => (float) (clone $pending)->sum('amount'),
            'received_count' => (clone $received)->count(),
            'received_amount' => (float) (clone $received)->sum('return_amount'),
        ];
    }
}
