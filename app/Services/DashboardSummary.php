<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\Expense;
use App\Models\FundTransaction;
use App\Models\IpoApplication;
use App\Models\ProfitPayout;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Every figure on the dashboard, computed with a handful of grouped queries.
 *
 * Account::current_balance runs ~9 queries per account and the dashboard used to
 * ask for it several times per account. Here each account's balance, blocked and
 * invested amounts come out of six aggregate queries in total. The balance formula
 * is the same one Account::current_balance uses (a test keeps the two in step).
 *
 * All queries go through the tenant-scoped models, so a login only ever sees its
 * own books. One instance per request caches the account figures.
 */
class DashboardSummary
{
    /** @var Collection<int, array{balance: float, blocked: float, invested: float}>|null */
    private ?Collection $figures = null;

    public const PERIODS = [
        'all' => 'All time (બધું)',
        'today' => 'Today (આજે)',
        '7d' => 'Last 7 days (૭ દિવસ)',
        '30d' => 'Last 30 days (૩૦ દિવસ)',
    ];

    /** @return array{0: ?CarbonInterface, 1: CarbonInterface} */
    public static function periodRange(?string $period): array
    {
        $to = now()->endOfDay();

        return match ($period) {
            'today' => [now()->startOfDay(), $to],
            '7d' => [now()->subDays(6)->startOfDay(), $to],
            '30d' => [now()->subDays(29)->startOfDay(), $to],
            default => [null, $to],
        };
    }

    /**
     * Balance, blocked and invested money for every account, keyed by account id.
     * A sub-account holds real money (moved in from a main account), so an
     * application paid from it is debited from it alone; a main account's are the
     * ones paid from it directly — the same rule as Account::ipoApplications().
     *
     * @return Collection<int, array{balance: float, blocked: float, invested: float}>
     */
    public function accountFigures(): Collection
    {
        if ($this->figures !== null) {
            return $this->figures;
        }

        $funds = FundTransaction::query()
            ->selectRaw("account_id, SUM(CASE WHEN type = 'given' THEN amount ELSE -amount END) as net")
            ->groupBy('account_id')->pluck('net', 'account_id');

        $expensesOut = Expense::query()->whereIn('type', ['expense', 'udhar_given'])
            ->selectRaw('account_id, SUM(amount) as total')->groupBy('account_id')->pluck('total', 'account_id');
        $expensesIn = Expense::query()->where('type', 'udhar_received')
            ->selectRaw('account_id, SUM(amount) as total')->groupBy('account_id')->pluck('total', 'account_id');

        $payouts = ProfitPayout::query()
            ->selectRaw('account_id, SUM(amount) as total')->groupBy('account_id')->pluck('total', 'account_id');

        $transfersOut = AccountTransfer::query()
            ->selectRaw('from_account_id as account, SUM(amount) as total')->groupBy('from_account_id')->pluck('total', 'account');
        $transfersIn = AccountTransfer::query()
            ->selectRaw('to_account_id as account, SUM(amount) as total')->groupBy('to_account_id')->pluck('total', 'account');

        $byPayer = $this->applicationAggregates('account_id', onlyDirect: true);
        $bySub = Account::hasSubAccountColumn() ? $this->applicationAggregates('sub_account_id') : collect();

        return $this->figures = Account::query()->get()->mapWithKeys(function (Account $account) use (
            $funds, $expensesOut, $expensesIn, $payouts, $transfersOut, $transfersIn, $byPayer, $bySub
        ) {
            $id = $account->id;
            $apps = ($account->is_sub_account ? $bySub : $byPayer)->get($id);

            $balance = (float) $account->opening_balance
                + (float) ($funds[$id] ?? 0)
                - (float) ($apps->applied ?? 0)
                + (float) ($apps->returned ?? 0)
                - ((float) ($expensesOut[$id] ?? 0) - (float) ($expensesIn[$id] ?? 0))
                - (float) ($payouts[$id] ?? 0)
                - (float) ($transfersOut[$id] ?? 0)
                + (float) ($transfersIn[$id] ?? 0);

            return [$id => [
                'balance' => $balance,
                'blocked' => (float) ($apps->blocked ?? 0),
                'invested' => (float) ($apps->invested ?? 0),
            ]];
        });
    }

    /**
     * A main account together with its sub-accounts: what it holds in total and,
     * separately, the cash sitting in sub-accounts that is still to be brought back.
     *
     * @return array{balance: float, blocked: float, invested: float, in_sub_accounts: float}
     */
    public function rollup(int $mainAccountId): array
    {
        $figures = $this->accountFigures();
        $subIds = Account::hasSubAccountColumn()
            ? Account::query()->where('parent_id', $mainAccountId)->pluck('id')
            : collect();

        $own = $this->figuresFor($mainAccountId);
        $subs = $figures->only($subIds->all());

        return [
            'balance' => $own['balance'] + (float) $subs->sum('balance'),
            'blocked' => $own['blocked'] + (float) $subs->sum('blocked'),
            'invested' => $own['invested'] + (float) $subs->sum('invested'),
            'in_sub_accounts' => (float) $subs->sum('balance'),
        ];
    }

    /** @return array{balance: float, blocked: float, invested: float} */
    public function figuresFor(int $accountId): array
    {
        return $this->accountFigures()->get($accountId, ['balance' => 0.0, 'blocked' => 0.0, 'invested' => 0.0]);
    }

    /**
     * The headline money position, over every active account. A sub-account holds
     * its own money (moved in from a main account) and an application is debited
     * from exactly one account, so nothing is counted twice.
     *
     * @return array{available: float, blocked: float, invested: float, total_funds: float, udhar_receivable: float, owed_to_sources: float, profit_owed: float, net_position: float}
     */
    public function totals(): array
    {
        $activeIds = Account::query()->where('is_active', true)->pluck('id');

        $figures = $this->accountFigures()->only($activeIds->all());

        $available = (float) $figures->sum('balance');
        $blocked = (float) $figures->sum('blocked');
        $invested = (float) $figures->sum('invested');

        $udhar = (float) Expense::query()->where('type', 'udhar_given')->where('status', 'pending')->sum('amount');

        $owed = (float) FundTransaction::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'given' THEN amount ELSE -amount END), 0) as due")
            ->value('due');

        $totalFunds = $available + $blocked + $invested;

        // Profit already realised in the accounts but belonging to sources and
        // applicants until it is paid out — a liability, just like their principal.
        $everything = $this->profit(null, null);
        $profitOwed = max(0.0, $everything['source_share'] + $everything['applicant_share'] - (float) ProfitPayout::query()->sum('amount'));

        return [
            'available' => $available,
            'blocked' => $blocked,
            'invested' => $invested,
            'total_funds' => $totalFunds,
            'udhar_receivable' => $udhar,
            'owed_to_sources' => $owed,
            'profit_owed' => $profitOwed,
            'net_position' => $totalFunds + $udhar - $owed - $profitOwed,
        ];
    }

    /**
     * Sold applications whose sale date falls in the window (falling back to the
     * applied date when no sale date was recorded).
     *
     * @return Collection<int, IpoApplication>
     */
    public function soldApplications(?CarbonInterface $from, ?CarbonInterface $to): Collection
    {
        return IpoApplication::query()
            ->where('status', 'sold')
            ->when($from, fn ($q) => $q->whereRaw('DATE(COALESCE(sold_date, return_date, applied_date)) >= ?', [$from->toDateString()]))
            ->when($to, fn ($q) => $q->whereRaw('DATE(COALESCE(sold_date, return_date, applied_date)) <= ?', [$to->toDateString()]))
            ->with(['source', 'applicant', 'ipo', 'sales'])
            ->get();
    }

    /**
     * Realised profit and how it divides, for the window.
     *
     * @return array{profit: float, source_share: float, applicant_share: float, your_share: float, count: int}
     */
    public function profit(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $sold = $this->soldApplications($from, $to);

        return [
            'profit' => (float) $sold->sum(fn (IpoApplication $a) => (float) $a->profit),
            'source_share' => (float) $sold->sum(fn (IpoApplication $a) => (float) $a->source_profit_share),
            'applicant_share' => (float) $sold->sum(fn (IpoApplication $a) => (float) $a->applicant_profit_share),
            'your_share' => (float) $sold->sum(fn (IpoApplication $a) => (float) $a->your_profit_share),
            'count' => $sold->count(),
        ];
    }

    /**
     * Realised profit per day or per month across the window — [label => profit].
     * Short windows are daily; "all time" is the last 12 months.
     *
     * @return array<string, float>
     */
    public function profitSeries(?string $period): array
    {
        $daily = in_array($period, ['today', '7d', '30d'], true);

        [$from, $to] = static::periodRange($period);

        if (! $daily) {
            $from = now()->subMonths(11)->startOfMonth();
        }

        $series = [];
        $cursor = $from->copy();

        while ($cursor <= $to) {
            $series[$daily ? $cursor->format('Y-m-d') : $cursor->format('Y-m')] = 0.0;
            $cursor = $daily ? $cursor->addDay() : $cursor->addMonthNoOverflow();
        }

        foreach ($this->soldApplications($from, $to) as $application) {
            $date = $application->sold_date ?? $application->return_date ?? $application->applied_date;
            $key = $daily ? $date->format('Y-m-d') : $date->format('Y-m');

            if (array_key_exists($key, $series)) {
                $series[$key] += (float) $application->profit;
            }
        }

        $labelled = [];

        foreach ($series as $key => $value) {
            $label = $daily
                ? Carbon::parse($key)->format('d M')
                : Carbon::parse($key.'-01')->format('M Y');
            $labelled[$label] = round($value, 2);
        }

        return $labelled;
    }

    /** Applied / returned / blocked / invested per account, grouped by the given column. */
    private function applicationAggregates(string $column, bool $onlyDirect = false): Collection
    {
        return IpoApplication::query()
            ->whereNotNull($column)
            ->when($onlyDirect && Account::hasSubAccountColumn(), fn ($q) => $q->whereNull('sub_account_id'))
            ->selectRaw("
                {$column} as acc,
                SUM(amount) as applied,
                SUM(CASE WHEN return_amount IS NOT NULL THEN return_amount WHEN status = 'refunded' THEN amount ELSE 0 END)
                    + SUM(COALESCE((SELECT SUM(s.net_amount) FROM application_sales s WHERE s.ipo_application_id = ipo_applications.id), 0)) as returned,
                SUM(CASE WHEN status = 'applied' THEN amount ELSE 0 END) as blocked,
                SUM(CASE WHEN status IN ('allotted', 'partial_allotted')
                    THEN (amount - COALESCE(return_amount, 0)) * (1 - CASE
                        WHEN COALESCE(shares_allotted, 0) <= 0 THEN 0
                        WHEN COALESCE(shares_sold, 0) >= shares_allotted THEN 1
                        ELSE COALESCE(shares_sold, 0) * 1.0 / shares_allotted END)
                    ELSE 0 END) as invested
            ")
            ->groupBy($column)
            ->get()
            ->keyBy('acc');
    }
}
