<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\FundTransaction;
use App\Models\ProfitPayout;
use App\Models\Source;
use App\Services\DashboardSummary;
use App\Services\SourceShare;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where the money is and who has to put it where.
 *
 * Part 1 - collect: every account holding free money, with a ready message ("Please put Rs X in
 * Om SBI") to send to its holder, and a button that records the transfer once they have done it.
 * Part 2 - pay the sources back: money given back plus the profit share, worked out per source,
 * with a ready message and a button that records the payment.
 */
class MoneyDeskPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'IPO Management';

    protected static ?string $navigationLabel = 'Money Desk';

    protected static ?string $title = 'Money Desk';

    protected static ?string $slug = 'money-desk';

    protected static ?int $navigationSort = -3;

    protected static string $view = 'filament.pages.money-desk';

    /** The bank everything is collected into (account id). */
    public string $destination = '';

    /** "account id-bank id" => amount actually put in (blank = the whole amount of that line). */
    public array $amounts = [];

    /** The account the sources are paid from (account id). */
    public string $payFrom = '';

    /** source id => principal / profit to pay now (blank = everything due). */
    public array $principal = [];

    public array $profit = [];

    public function mount(): void
    {
        $default = Account::query()->where('is_active', true)->where('name', 'like', '%SBI%')->orderBy('id')->value('id')
            ?? Account::query()->where('is_active', true)->where('type', 'bank')->orderBy('id')->value('id');

        $this->destination = (string) $default;
        $this->payFrom = (string) $default;
    }

    public function getSubheading(): ?string
    {
        return 'Who has to put how much money where, and what each source is to be paid.';
    }

    /** Main banks and cash to choose from. */
    public function bankOptions(): Collection
    {
        return Account::query()->where('is_active', true)->mainAccounts()->orderBy('name')->get(['id', 'name', 'bank_name', 'account_number', 'type']);
    }

    public function destinationAccount(): ?Account
    {
        return $this->destination !== '' ? Account::find((int) $this->destination) : null;
    }

    /**
     * Accounts holding free money, biggest first, with where each rupee goes back.
     *
     * Money is returned to the bank it came from: what you sent to an account (minus what it has
     * already sent back) is owed to that bank. Anything above that (profit) goes to the bank
     * chosen at the top.
     *
     * @return Collection<int, array{id: int, name: string, holder: ?string, free: float, blocked: float, refund_pending: float, lines: array}>
     */
    public function collectList(): Collection
    {
        $figures = (new DashboardSummary)->accountFigures();
        $names = Account::query()->pluck('name', 'id');

        // net[to][from] = what `from` has sent to `to` and not had back.
        $net = [];
        foreach (AccountTransfer::query()->get(['from_account_id', 'to_account_id', 'amount']) as $t) {
            $net[$t->to_account_id][$t->from_account_id] = ($net[$t->to_account_id][$t->from_account_id] ?? 0) + (float) $t->amount;
            $net[$t->from_account_id][$t->to_account_id] = ($net[$t->from_account_id][$t->to_account_id] ?? 0) - (float) $t->amount;
        }

        return Account::query()
            ->where('is_active', true)
            ->mainAccounts()
            ->where('type', '!=', 'cash')
            ->whereKeyNot((int) $this->destination)
            ->get()
            ->map(function (Account $a) use ($figures, $net, $names) {
                $f = $figures[$a->id] ?? ['balance' => 0, 'blocked' => 0, 'refund_pending' => 0];
                $free = round((float) $f['balance'], 2);

                return [
                    'id' => $a->id,
                    'name' => $a->name,
                    'holder' => $a->holder_name,
                    'free' => $free,
                    'blocked' => round((float) $f['blocked'], 2),
                    'refund_pending' => round((float) ($f['refund_pending'] ?? 0), 2),
                    'lines' => $this->returnLines($a->id, $free, $net[$a->id] ?? [], $names),
                ];
            })
            ->filter(fn (array $row) => $row['free'] > 0.0 || $row['blocked'] > 0.0)
            ->sortByDesc('free')
            ->values();
    }

    /**
     * Which bank gets how much of an account's free money.
     *
     * @param  array<int, float>  $owed  bank id => what that bank sent in and has not had back
     * @return array<int, array{bank_id: int, bank: string, amount: float, owed: bool}>
     */
    private function returnLines(int $accountId, float $free, array $owed, Collection $names): array
    {
        $lines = [];
        $left = $free;

        arsort($owed);

        foreach ($owed as $bankId => $amount) {
            if ($amount <= 0.0 || $left <= 0.0 || (int) $bankId === $accountId) {
                continue;
            }

            $part = round(min($amount, $left), 2);
            $lines[] = ['bank_id' => (int) $bankId, 'bank' => $names[$bankId] ?? 'Account #'.$bankId, 'amount' => $part, 'owed' => true];
            $left = round($left - $part, 2);
        }

        if ($left > 0.0 && $this->destination !== '') {
            $dest = (int) $this->destination;
            $existing = array_search($dest, array_column($lines, 'bank_id'), true);

            if ($existing !== false) {
                $lines[$existing]['amount'] = round($lines[$existing]['amount'] + $left, 2);
            } else {
                $lines[] = ['bank_id' => $dest, 'bank' => $names[$dest] ?? 'Account #'.$dest, 'amount' => $left, 'owed' => false];
            }
        }

        return $lines;
    }

    /** One bank's part of an account's money has been put in: record the transfer. */
    public function collectedLine(int $accountId, int $bankId): void
    {
        $row = $this->collectList()->firstWhere('id', $accountId);
        $line = collect($row['lines'] ?? [])->firstWhere('bank_id', $bankId);

        if (! $line) {
            return;
        }

        $key = $accountId.'-'.$bankId;
        $typed = $this->amounts[$key] ?? null;
        $amount = round(max(($typed === null || $typed === '') ? $line['amount'] : (float) $typed, 0.0), 2);

        if ($amount <= 0) {
            Notification::make()->warning()->title('Enter the amount that was put in')->send();

            return;
        }

        AccountTransfer::create([
            'from_account_id' => $accountId,
            'to_account_id' => $bankId,
            'amount' => $amount,
            'transfer_date' => now()->toDateString(),
            'notes' => 'Returned to '.$line['bank'],
        ]);

        unset($this->amounts[$key]);

        Notification::make()->success()
            ->title(SourceShare::inr($amount).' moved to '.$line['bank'])
            ->body('From '.$row['name'])
            ->send();
    }
    /**
     * What each source is to be paid: the money given and not yet returned, plus the profit share
     * earned on sold applications and not yet paid.
     *
     * @return Collection<int, array{id: int, name: string, given: float, returned: float, principal: float, profit: float, total: float, mobile: ?string}>
     */
    public function sourceList(): Collection
    {
        return Source::query()->where('is_active', true)->orderBy('name')->get()
            ->map(function (Source $s) {
                $principal = round(max((float) $s->balance_due, 0.0), 2);
                $profit = round(max((float) $s->profit_share_pending, 0.0), 2);

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'given' => (float) $s->total_given,
                    'returned' => (float) $s->total_returned,
                    'principal' => $principal,
                    'profit' => $profit,
                    'total' => round($principal + $profit, 2),
                    'mobile' => $s->mobile,
                ];
            })
            ->filter(fn (array $row) => $row['total'] > 0.0)
            ->values();
    }

    /** Pay one source: the principal comes back as "returned", the profit as a profit payout. */
    public function paySource(int $sourceId): void
    {
        $row = $this->sourceList()->firstWhere('id', $sourceId);
        $account = $this->payFrom !== '' ? Account::find((int) $this->payFrom) : null;

        if (! $row || ! $account) {
            return;
        }

        $principal = $this->typedOr($this->principal[$sourceId] ?? null, $row['principal']);
        $profit = $this->typedOr($this->profit[$sourceId] ?? null, $row['profit']);

        if ($principal + $profit <= 0) {
            Notification::make()->warning()->title('Nothing to pay')->send();

            return;
        }

        DB::transaction(function () use ($sourceId, $account, $principal, $profit) {
            if ($principal > 0) {
                FundTransaction::create([
                    'source_id' => $sourceId,
                    'account_id' => $account->id,
                    'type' => 'returned',
                    'amount' => $principal,
                    'transaction_date' => now()->toDateString(),
                    'notes' => 'Paid back from Money Desk',
                ]);
            }

            if ($profit > 0) {
                ProfitPayout::create([
                    'source_id' => $sourceId,
                    'account_id' => $account->id,
                    'amount' => $profit,
                    'payout_date' => now()->toDateString(),
                    'notes' => 'Profit share paid from Money Desk',
                ]);
            }
        });

        unset($this->principal[$sourceId], $this->profit[$sourceId]);

        Notification::make()->success()
            ->title($row['name'].': '.SourceShare::inr($principal + $profit).' paid')
            ->body('Money '.SourceShare::inr($principal).' + profit '.SourceShare::inr($profit).' from '.$account->name)
            ->send();
    }

    private function typedOr(mixed $typed, float $default): float
    {
        return round(max(($typed === null || $typed === '') ? $default : (float) $typed, 0.0), 2);
    }

    /** The cards at the top. */
    public function totals(): array
    {
        $collect = $this->collectList();
        $sources = $this->sourceList();

        return [
            'to_collect' => (float) $collect->sum('free'),
            'accounts' => $collect->where('free', '>', 0)->count(),
            'still_blocked' => (float) $collect->sum('blocked'),
            'to_pay_sources' => (float) $sources->sum('total'),
        ];
    }
}
