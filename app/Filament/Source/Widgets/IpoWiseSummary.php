<?php

namespace App\Filament\Source\Widgets;

use App\Filament\Source\Concerns\InteractsWithSource;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * The IPO-by-IPO board: one row per IPO, showing what went in with this source's
 * money, how many applications got shares, what came back, and their cut.
 *
 * Built from a grouped collection rather than a table widget because the rows
 * are aggregates over applications, not applications themselves.
 */
class IpoWiseSummary extends Widget
{
    use InteractsWithSource;

    protected static string $view = 'filament.source.widgets.ipo-wise-summary';

    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    public function getRows(): Collection
    {
        return $this->source()->ipoWiseSummary();
    }

    public function getTotals(): array
    {
        $rows = $this->getRows();

        return [
            'applied' => (float) $rows->sum('applied'),
            'returned' => (float) $rows->sum('returned'),
            'shares_allotted' => (int) $rows->sum('shares_allotted'),
            'my_share' => (float) $rows->sum('my_share'),
        ];
    }
}
