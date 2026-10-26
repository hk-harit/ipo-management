<?php

namespace App\Filament\Pages;

use App\Services\DashboardSummary;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $navigationLabel = 'Dashboard';

    /**
     * The period narrows the profit-based widgets (profit card, profit trend,
     * profit shares). Balances are always "right now", so they ignore it.
     */
    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Section::make()
                ->schema([
                    ToggleButtons::make('period')
                        ->label('Profit period (નફાનો સમયગાળો)')
                        ->options(DashboardSummary::PERIODS)
                        ->default('all')
                        ->inline()
                        ->grouped(),
                ])
                ->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('accountOverview')
                ->label('Account Overview (ખાતાવાર હિસાબ)')
                ->icon('heroicon-o-table-cells')
                ->url(AccountOverviewPage::getUrl()),
        ];
    }

    public function getColumns(): int|string|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
