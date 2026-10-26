<?php

namespace App\Filament\Pages;

use App\Models\Ipo;
use App\Services\AccountOverview;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * One IPO, start to finish, on a single screen: how much was applied, in which lots,
 * under which source, from which accounts, with whose PAN — and who gets what share.
 */
class IpoDashboardPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'IPO Dashboard (IPO નો હિસાબ)';

    protected static ?string $title = 'IPO Dashboard (IPO નો હિસાબ)';

    protected static ?string $slug = 'ipo-dashboard';

    protected static ?int $navigationSort = -2;

    protected static string $view = 'filament.pages.ipo-dashboard';

    /** The IPO id being shown. Starts on the one applied to most recently. */
    public string $ipo = '';

    public function mount(): void
    {
        $this->ipo = (string) ((new AccountOverview)->ipoOptions()->first()['id'] ?? '');
    }

    public function getSubheading(): ?string
    {
        return 'Ek IPO ma ketlu bharyu, kya source ma, kone kone, ane kone ketlu commission — badhu ek j screen par.';
    }

    public function ipoOptions(): Collection
    {
        return (new AccountOverview)->ipoOptions();
    }

    /** Everything on the page for the selected IPO, or null when nothing has been applied yet. */
    public function report(): ?array
    {
        $ipoId = (int) $this->ipo;
        $ipo = $ipoId ? Ipo::find($ipoId) : null;

        if (! $ipo) {
            return null;
        }

        $data = (new AccountOverview)->build($ipoId);
        $rows = $data['source_groups']->flatMap(fn (array $g) => $g['rows'])->values();

        // Lot-wise: round to whole lots so ₹13,500 and ₹15,000 count as 1 lot like ₹13,428.
        $lots = $rows
            ->groupBy(fn (array $r) => $r['lots'] ? max(1, (int) round($r['lots'])) : 0)
            ->map(fn (Collection $g, $lot) => [
                'lots' => (int) $lot,
                'apps' => $g->count(),
                'amount' => (float) $g->sum('amount'),
                'applicants' => $g->pluck('applicant')->unique()->count(),
            ])
            ->sortBy('lots')->values();

        $statuses = $rows
            ->groupBy('status')
            ->map(fn (Collection $g, string $status) => [
                'status' => $status,
                'apps' => $g->count(),
                'amount' => (float) $g->sum('amount'),
            ])->values();

        $accounts = $data['accounts']->filter(fn (array $a) => $a['focus_count'] > 0)->values();

        return [
            'ipo' => $ipo,
            'data' => $data,
            'rows' => $rows,
            'lots' => $lots,
            'statuses' => $statuses,
            'accounts' => $accounts,
            'applicants' => $rows->pluck('pan')->unique()->count(),
            'with_commission' => $rows->where('applicant_pct', '>', 0)->count(),
            'total_lots' => (int) $lots->sum(fn (array $l) => $l['lots'] * $l['apps']),
        ];
    }
}
