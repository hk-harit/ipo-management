<?php

namespace App\Filament\Widgets;

use App\Models\IpoApplication;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class AllotmentOutcomeChart extends ChartWidget
{
    protected static ?string $heading = 'Application Status & Allotment Ratio';

    protected static ?int $sort = 5;

    protected static ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    public static function canView(): bool
    {
        return IpoApplication::exists();
    }

    protected function getData(): array
    {
        $applied = IpoApplication::where('status', 'applied')->count();
        $allotted = IpoApplication::whereIn('status', ['allotted', 'partial_allotted'])->count();
        $sold = IpoApplication::where('status', 'sold')->count();
        $refunded = IpoApplication::whereIn('status', IpoApplication::NOT_ALLOTTED)->count();

        return [
            'datasets' => [
                [
                    'label' => 'Applications',
                    'data' => [$applied, $allotted, $sold, $refunded],
                    'backgroundColor' => [
                        '#f59e0b', // Amber: Applied
                        '#10b981', // Emerald: Allotted
                        '#3b82f6', // Blue: Sold
                        '#94a3b8', // Gray: Refunded
                    ],
                    'borderWidth' => 2,
                ],
            ],
            'labels' => [
                "Pending ({$applied})",
                "Allotted ({$allotted})",
                "Sold ({$sold})",
                "Refunded ({$refunded})",
            ],
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
                                let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                let pct = total > 0 ? Math.round((val / total) * 100) : 0;
                                return ' ' + context.label + ': ' + val + ' apps (' + pct + '%)';
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
