<?php

namespace App\Filament\Source\Pages;

use App\Filament\Source\Concerns\InteractsWithSource;
use App\Models\IpoApplication;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every single application funded by the signed-in source, one row each —
 * the detail behind the IPO-wise summary on the dashboard.
 */
class MyApplications extends Page implements HasTable
{
    use InteractsWithSource;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'My Applications';

    protected static ?string $title = 'My Applications';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.source.pages.table-page';

    public function getSubheading(): ?string
    {
        return 'Every application made with your money, newest first.';
    }

    /**
     * Scoped by the signed-in source's own id — never by anything the request
     * can supply. The tenant global scope narrows it to the right admin too.
     */
    protected function getTableQuery(): Builder
    {
        return IpoApplication::query()
            ->where('source_id', $this->source()->getKey())
            ->with(['ipo', 'applicant', 'account']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                TextColumn::make('ipo.name')
                    ->label('IPO')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('applied_date')
                    ->label('Applied')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('applicant.name')
                    ->label('In Whose Name')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'applied' => 'Result awaited',
                        'not_allotted' => 'Not allotted — refund coming',
                        'refunded' => 'Not allotted',
                        'allotted' => 'Allotted',
                        'partial_allotted' => 'Partly allotted',
                        'sold' => 'Sold',
                        default => str($state)->headline(),
                    })
                    ->color(fn (string $state) => match ($state) {
                        'applied' => 'warning',
                        'refunded' => 'gray',
                        'not_allotted' => 'danger',
                        'allotted', 'partial_allotted' => 'success',
                        'sold' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('shares_allotted')
                    ->label('Shares')
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('return_amount')
                    ->label('Came Back')
                    ->money('INR')
                    ->placeholder('—')
                    ->description(fn (IpoApplication $record) => $record->return_date?->format('d M Y')),
                TextColumn::make('source_profit_share')
                    ->label('Your Share')
                    ->state(fn (IpoApplication $record) => $record->source_profit_share)
                    ->money('INR')
                    ->placeholder('—')
                    ->weight('bold')
                    ->color('info')
                    ->description(fn (IpoApplication $record) => $record->status === 'sold'
                        ? $record->effective_source_share_percent.'% of profit'
                        : null),
                TextColumn::make('account.name')
                    ->label('Account')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'applied' => 'Result awaited',
                        'not_allotted' => 'Not allotted — refund coming',
                        'refunded' => 'Not allotted',
                        'allotted' => 'Allotted',
                        'partial_allotted' => 'Partly allotted',
                        'sold' => 'Sold',
                    ]),
                SelectFilter::make('ipo_id')
                    ->label('IPO')
                    ->relationship('ipo', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('applied_date', 'desc')
            ->emptyStateHeading('No applications yet')
            ->emptyStateDescription('Nothing has been applied with your money so far.')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
