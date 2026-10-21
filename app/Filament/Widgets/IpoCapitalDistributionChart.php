<?php

namespace App\Filament\Widgets;

use App\Models\Ipo;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class IpoCapitalDistributionChart extends ChartWidget
{
    protected static ?string $heading = 'Capital Distribution by IPO (રોકાણ ક્યાં કેટલું છે)';

    protected static ?int $sort = 3;

    protected static ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    public static function canView(): bool
    {
        return Ipo::whereHas('applications')->exists();
    }

    protected function getData(): array
    {
        $ipos = Ipo::whereHas('applications')
            ->with('applications')
            ->get()
            ->map(fn (Ipo $i) => [
                'name' => $i->name,
                'amount' => (float) $i->applications->sum('amount'),
            ])
            ->filter(fn ($item) => $item['amount'] > 0)
            ->sortByDesc('amount')
            ->take(8);

        $colors = [
            '#6366f1', // Indigo
            '#10b981', // Emerald
            '#f59e0b', // Amber
            '#ec4899', // Pink
            '#3b82f6', // Blue
            '#8b5cf6', // Violet
            '#14b8a6', // Teal
            '#f97316', // Orange
        ];

        return [
            'datasets' => [
                [
                    'label' => 'Total Invested (₹)',
                    'data' => $ipos->pluck('amount')->values()->all(),
                    'backgroundColor' => array_slice($colors, 0, $ipos->count()),
                    'borderWidth' => 2,
                ],
            ],
            'labels' => $ipos->pluck('name')->values()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 12,
                        },
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let val = context.raw || 0;
                                return ' ₹' + val.toLocaleString('en-IN');
                            }
                        }
                    }
                },
                cutout: '60%',
            }
        JS);
    }
}
