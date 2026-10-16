<?php

namespace App\Filament\Resources\ExpenseResource\Widgets;

use App\Models\Expense;
use Carbon\Carbon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class MonthlyExpenseChart extends ChartWidget
{
    protected static ?string $heading = 'Monthly Expenses & Udhar (Last 6 Months)';

    protected static ?string $maxHeight = '240px';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $labels = [];
        $expenseData = [];
        $udharData = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $labels[] = $date->format('M Y');

            $start = $date->copy()->startOfMonth()->toDateString();
            $end = $date->copy()->endOfMonth()->toDateString();

            $expenseData[] = (float) Expense::where('type', 'expense')
                ->whereBetween('expense_date', [$start, $end])
                ->sum('amount');

            $udharData[] = (float) Expense::where('type', 'udhar_given')
                ->whereBetween('expense_date', [$start, $end])
                ->sum('amount');
        }

        return [
            'datasets' => [
                [
                    'label' => 'Regular Expenses (₹)',
                    'data' => $expenseData,
                    'backgroundColor' => '#ef4444',
                    'borderRadius' => 6,
                ],
                [
                    'label' => 'Udhar Given (₹)',
                    'data' => $udharData,
                    'backgroundColor' => '#f59e0b',
                    'borderRadius' => 6,
                ],
            ],
            'labels' => $labels,
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
                scales: {
                    y: {
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
