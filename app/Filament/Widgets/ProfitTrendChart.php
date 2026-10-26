<?php

namespace App\Filament\Widgets;

use App\Services\DashboardSummary;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ProfitTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Realised Profit (નફો)';

    protected static ?int $sort = 3;

    protected static ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 'full';

    public function getDescription(): ?string
    {
        return in_array($this->filters['period'] ?? 'all', ['today', '7d', '30d'], true)
            ? 'Per day, by sale date'
            : 'Per month, last 12 months, by sale date';
    }

    protected function getData(): array
    {
        $series = (new DashboardSummary)->profitSeries($this->filters['period'] ?? 'all');

        return [
            'datasets' => [
                [
                    'label' => 'Profit (₹)',
                    'data' => array_values($series),
                    'backgroundColor' => array_map(fn (float $v) => $v >= 0 ? '#10b981' : '#f43f5e', array_values($series)),
                    'borderRadius' => 6,
                ],
            ],
            'labels' => array_keys($series),
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
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return ' ₹' + Number(context.raw || 0).toLocaleString('en-IN');
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function(value) { return '₹' + Number(value).toLocaleString('en-IN'); }
                        }
                    }
                }
            }
        JS);
    }
}
