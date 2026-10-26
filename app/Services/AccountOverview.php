<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\FundTransaction;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use Illuminate\Support\Collection;

/**
 * One account-wise picture of the books: for every account, which sources put money
 * in (and how much), what was applied from it (all IPOs, and for the chosen IPO),
 * what is blocked / in demat / refunded, and what is left.
 *
 * Built from a few grouped queries plus the application rows in memory, and the
 * balances come from DashboardSummary so this screen can never disagree with the
 * dashboard.
 */
class AccountOverview
{
    public function __construct(private readonly DashboardSummary $summary = new DashboardSummary) {}

    /** IPOs that have at least one application, newest activity first, for the picker. */
    public function ipoOptions(): Collection
    {
        $latest = IpoApplication::query()
            ->selectRaw('ipo_id, COUNT(*) as apps, SUM(amount) as total, MAX(applied_date) as last_applied')
            ->groupBy('ipo_id')
            ->orderByDesc('last_applied')
            ->get();

        $names = Ipo::query()->whereIn('id', $latest->pluck('ipo_id'))->pluck('name', 'id');

        return $latest->map(fn ($row) => [
            'id' => (int) $row->ipo_id,
            'name' => $names[$row->ipo_id] ?? 'IPO #'.$row->ipo_id,
            'apps' => (int) $row->apps,
            'total' => (float) $row->total,
        ]);
    }

    /**
     * @param  int|null  $ipoId  focus on one IPO; null = every IPO
     * @return array{accounts: Collection, sources: Collection, totals: array, ipo: ?array}
     */
    public function build(?int $ipoId): array
    {
        $accounts = Account::query()->where('is_active', true)->orderBy('name')->get();
        $hasSub = Account::hasSubAccountColumn();
        $figures = $this->summary->accountFigures();
        $sourceNames = Source::query()->pluck('name', 'id');

        // Money a source put in or took back, per account and source.
        $funds = FundTransaction::query()
            ->selectRaw("account_id, source_id, SUM(CASE WHEN type = 'given' THEN amount ELSE 0 END) as given, SUM(CASE WHEN type = 'returned' THEN amount ELSE 0 END) as returned")
            ->groupBy('account_id', 'source_id')
            ->get()
            ->groupBy('account_id');

        $transfersIn = AccountTransfer::query()->selectRaw('to_account_id as acc, SUM(amount) as total')->groupBy('to_account_id')->pluck('total', 'acc');
        $transfersOut = AccountTransfer::query()->selectRaw('from_account_id as acc, SUM(amount) as total')->groupBy('from_account_id')->pluck('total', 'acc');

        // The account that actually paid for an application: the sub-account when set.
        $applications = IpoApplication::query()
            ->with(['applicant:id,name,pan_number', 'source:id,name', 'ipo:id,name'])
            ->orderBy('applied_date')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (IpoApplication $a) => ($hasSub && $a->sub_account_id) ? $a->sub_account_id : $a->account_id);

        $rows = $accounts->map(function (Account $account) use ($figures, $funds, $transfersIn, $transfersOut, $applications, $sourceNames, $ipoId) {
            $id = $account->id;
            $all = $applications->get($id, collect());
            $focus = $ipoId ? $all->where('ipo_id', $ipoId) : $all;

            $received = ($funds->get($id) ?? collect())->map(fn ($row) => [
                'source' => $sourceNames[$row->source_id] ?? 'Source #'.$row->source_id,
                'given' => (float) $row->given,
                'returned' => (float) $row->returned,
            ])->sortByDesc('given')->values();

            $figure = $figures->get($id, ['balance' => 0.0, 'blocked' => 0.0, 'invested' => 0.0]);

            return [
                'account' => $account,
                'is_sub' => (bool) $account->is_sub_account,
                'parent_id' => $account->parent_id,
                'received' => $received,
                'given_total' => (float) $received->sum('given'),
                'returned_total' => (float) $received->sum('returned'),
                'transfers_in' => (float) ($transfersIn[$id] ?? 0),
                'transfers_out' => (float) ($transfersOut[$id] ?? 0),
                'all_count' => $all->count(),
                'all_amount' => (float) $all->sum('amount'),
                'focus_count' => $focus->count(),
                'focus_amount' => (float) $focus->sum('amount'),
                'focus_by_source' => $focus->groupBy(fn ($a) => $a->source?->name ?? 'No source')
                    ->map(fn (Collection $apps, string $name) => ['source' => $name, 'count' => $apps->count(), 'amount' => (float) $apps->sum('amount')])
                    ->sortByDesc('amount')->values(),
                'blocked' => $figure['blocked'],
                'invested' => $figure['invested'],
                'balance' => $figure['balance'],
                'applications' => $focus->values(),
            ];
        });

        // Main accounts first, each followed by its sub-accounts.
        $ordered = collect();
        foreach ($rows->where('is_sub', false) as $main) {
            $ordered->push($main);
            foreach ($rows->where('parent_id', $main['account']->id) as $sub) {
                $ordered->push($sub);
            }
        }
        foreach ($rows->where('is_sub', true)->reject(fn ($r) => $ordered->contains($r)) as $orphan) {
            $ordered->push($orphan);
        }

        $sources = Source::query()->orderBy('name')->get()->map(function (Source $source) use ($applications, $ipoId, $accounts) {
            $apps = $applications->flatten(1)->where('source_id', $source->id);
            $focus = $ipoId ? $apps->where('ipo_id', $ipoId) : $apps;

            return [
                'source' => $source,
                'given' => $source->total_given,
                'returned' => $source->total_returned,
                'due' => $source->balance_due,
                'focus_count' => $focus->count(),
                'focus_amount' => (float) $focus->sum('amount'),
                'focus_accounts' => $focus->groupBy(fn ($a) => ($a->sub_account_id ?: $a->account_id))
                    ->map(fn (Collection $list, $accId) => ($accounts->firstWhere('id', $accId)?->name ?? '#'.$accId).' ₹'.number_format($list->sum('amount'), 0))
                    ->values()->implode(', '),
            ];
        })->filter(fn ($s) => $s['given'] > 0 || $s['focus_count'] > 0)->values();

        $totals = [
            'given' => (float) $ordered->sum('given_total'),
            'returned' => (float) $ordered->sum('returned_total'),
            'focus_count' => (int) $ordered->sum('focus_count'),
            'focus_amount' => (float) $ordered->sum('focus_amount'),
            'blocked' => (float) $ordered->sum('blocked'),
            'invested' => (float) $ordered->sum('invested'),
            'balance' => (float) $ordered->sum('balance'),
            'short_accounts' => $ordered->filter(fn ($r) => $r['balance'] < 0)->count(),
        ];

        $ipo = $ipoId ? $this->ipoOptions()->firstWhere('id', $ipoId) : null;

        return ['accounts' => $ordered, 'sources' => $sources, 'totals' => $totals, 'ipo' => $ipo];
    }
}
