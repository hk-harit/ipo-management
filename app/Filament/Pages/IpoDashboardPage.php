<?php

namespace App\Filament\Pages;

use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Services\AccountOverview;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Every IPO that was applied to, as a list. Click one to see how many applications went in,
 * how many got shares (લાગ્યા), in which accounts they landed, and under which source.
 */
class IpoDashboardPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'IPO Dashboard (IPO નો હિસાબ)';

    protected static ?string $title = 'IPO Dashboard (IPO નો હિસાબ)';

    protected static ?string $slug = 'ipo-dashboard';

    protected static ?int $navigationSort = -2;

    protected static string $view = 'filament.pages.ipo-dashboard';

    private const ALLOTTED = ['allotted', 'partial_allotted', 'sold'];

    /** The IPO id being looked at; empty shows the list. In the URL, so refresh and Back work. */
    #[Url(as: 'ipo')]
    public string $ipo = '';

    public function openIpo(int $id): void
    {
        $this->ipo = (string) $id;
    }

    public function backToList(): void
    {
        $this->ipo = '';
    }

    public function getSubheading(): ?string
    {
        return $this->ipo === ''
            ? 'Je IPO ma apply karyu te badha. IPO par click karo — ketla bharya, ketla લાગ્યા, kaya account ma.'
            : null;
    }

    /** One row per IPO that has applications: counts of each result, newest activity first. */
    public function listing(): Collection
    {
        $rows = IpoApplication::query()
            ->selectRaw('ipo_id, status, COUNT(*) as n, SUM(amount) as amt, SUM(COALESCE(shares_allotted, 0)) as shares, MAX(applied_date) as last')
            ->groupBy('ipo_id', 'status')
            ->get()
            ->groupBy('ipo_id');

        $ipos = Ipo::query()->whereIn('id', $rows->keys())->get()->keyBy('id');

        return $rows->map(function (Collection $group, $id) use ($ipos) {
            $allotted = $group->whereIn('status', self::ALLOTTED);

            return [
                'ipo' => $ipos->get($id),
                'id' => (int) $id,
                'name' => $ipos->get($id)?->name ?? 'IPO #'.$id,
                'apps' => (int) $group->sum('n'),
                'amount' => (float) $group->sum('amt'),
                'allotted' => (int) $allotted->sum('n'),
                'shares' => (int) $allotted->sum('shares'),
                'not_allotted' => (int) $group->where('status', 'refunded')->sum('n'),
                'waiting' => (int) $group->where('status', 'applied')->sum('n'),
                'last' => $group->max('last'),
            ];
        })->sortByDesc('last')->values();
    }

    /** The selected IPO's report, or null when none is selected / it has no applications. */
    public function report(): ?array
    {
        $ipoId = (int) $this->ipo;
        $ipo = $ipoId ? Ipo::find($ipoId) : null;

        if (! $ipo) {
            return null;
        }

        $data = (new AccountOverview)->build($ipoId);
        $rows = $data['source_groups']->flatMap(fn (array $g) => $g['rows'])->values();

        $isAllotted = fn (array $r) => in_array($r['status'], self::ALLOTTED, true);
        $allottedRows = $rows->filter($isAllotted)
            ->map(fn (array $r) => $r + ['source_name' => $r['application']->source?->name, 'shares' => (int) $r['application']->shares_allotted])
            ->values();

        $allotment = [
            'allotted' => $allottedRows->count(),
            'allotted_amount' => (float) $allottedRows->sum('amount'),
            'allotted_shares' => (int) $allottedRows->sum('shares'),
            'not_allotted' => $rows->where('status', 'refunded')->count(),
            'waiting' => $rows->where('status', 'applied')->count(),
            'rows' => $allottedRows,
        ];

        // Account-wise: in which account the applications were made, and where the allotments landed.
        $accountResults = $data['accounts']
            ->filter(fn (array $a) => $a['focus_count'] > 0)
            ->map(function (array $a) {
                $apps = $a['applications'];
                $got = $apps->filter(fn (IpoApplication $x) => in_array($x->status, self::ALLOTTED, true));

                return [
                    'account' => $a['account'],
                    'is_sub' => $a['is_sub'],
                    'applied' => $apps->count(),
                    'applied_amount' => (float) $apps->sum('amount'),
                    'allotted' => $got->count(),
                    'shares' => (int) $got->sum('shares_allotted'),
                    'not_allotted' => $apps->where('status', 'refunded')->count(),
                    'waiting' => $apps->where('status', 'applied')->count(),
                    'allottees' => $got->sortBy(fn (IpoApplication $x) => $x->applicant?->name)
                        ->map(fn (IpoApplication $x) => ['name' => $x->applicant?->name, 'shares' => (int) $x->shares_allotted])
                        ->values(),
                    'balance' => $a['balance'],
                ];
            })
            ->sortByDesc('allotted')
            ->values();

        return [
            'ipo' => $ipo,
            'data' => $data,
            'rows' => $rows,
            'allotment' => $allotment,
            'accounts' => $accountResults,
        ];
    }
}
