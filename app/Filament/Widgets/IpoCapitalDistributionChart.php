<?php

namespace App\Filament\Widgets;

use App\Models\Ipo;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class IpoCapitalDistributionChart extends ChartWidget
{
    protected static ?string $heading = 'Capital Distribution by IPO (રોકાણ ક્યાં કેટલું છે)';

    protected static ?int $sort = 4;

    protected static ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    public static function canView(): bool
    {
        return Ipo::whereHas('applications', function ($query) {
            $query->whereIn('status', ['applied', 'allotted', 'partial_allotted']);
        })->exists();
    }

    protected function getData(): array
    {
        $ipos = Ipo::whereHas('applications', function ($query) {
            $query->whereIn('status', ['applied', 'allotted', 'partial_allotted']);
        })
            ->with(['applications' => function ($query) {
                $query->whereIn('status', ['applied', 'allotted', 'partial_allotted']);
            }])
            ->get()
            ->map(function (Ipo $i) {
                $activeAmount = $i->applications->sum(function ($app) {
                    if ($app->status === 'partial_allotted') {
                        return max(0.0, (float) $app->amount - (float) ($app->return_amount ?? 0));
                    }

                    return (float) $app->amount;
                });

                return [
                    'name' => $i->name,
                    'amount' => (float) $activeAmount,
                ];
            })
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
                    'label' => 'Current Invested (₹)',
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
                                return ' ' + context.label + ': ₹' + Number(val).toLocaleString('en-IN');
                            }
                        }
                    }
                },
                scales: {
                    x: { display: false },
                    y: { display: false }
                },
                cutout: '60%',
            }
        JS);
    }
}
