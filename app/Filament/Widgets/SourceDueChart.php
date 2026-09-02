<?php

namespace App\Filament\Widgets;

use App\Models\Source;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class SourceDueChart extends ChartWidget
{
    protected static ?string $heading = 'Due by Source';

    protected static ?int $sort = 3;

    protected static ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Source::all()->sum('balance_due') > 0;
    }

    protected function getData(): array
    {
        $sources = Source::where('is_active', true)->get()->filter(fn (Source $s) => $s->balance_due > 0);

        return [
            'datasets' => [
                [
                    'label' => 'Amount Due (₹)',
                    'data' => $sources->map(fn (Source $s) => $s->balance_due)->all(),
                    'backgroundColor' => '#6366f1',
                    'borderRadius' => 6,
                    'maxBarThickness' => 40,
                ],
            ],
            'labels' => $sources->map(fn (Source $s) => $s->name)->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => '₹' + value.toLocaleString('en-IN'),
                        },
                    },
                },
            }
        JS);
    }
}
