<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IpoResource\Pages;
use App\Filament\Resources\IpoResource\RelationManagers;
use App\Models\Ipo;
use App\Services\AllotmentCheck\AllotmentCheckService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class IpoResource extends Resource
{
    protected static ?string $model = Ipo::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'IPO Management';

    protected static ?string $navigationLabel = 'IPOs';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                DatePicker::make('open_date'),
                DatePicker::make('close_date'),
                DatePicker::make('allotment_date'),
                DatePicker::make('listing_date'),
                TextInput::make('price_per_share')->numeric()->prefix('₹'),
                TextInput::make('lot_size')->numeric(),
                Select::make('status')
                    ->options([
                        'upcoming' => 'Upcoming',
                        'open' => 'Open',
                        'closed' => 'Closed',
                        'allotted' => 'Allotted',
                        'listed' => 'Listed',
                    ])
                    ->required()
                    ->native(false)
                    ->default('upcoming'),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            Section::make('Auto Allotment Check')
                ->description("Optional — set these so the \"Check Allotment\" button on this IPO's applications can look up status automatically instead of checking each applicant by hand.")
                ->icon('heroicon-o-magnifying-glass-circle')
                ->collapsed(fn (?Ipo $record) => ! $record?->canAutoCheckAllotment())
                ->schema([
                    Select::make('registrar')
                        ->options(AllotmentCheckService::registrarOptions())
                        ->native(false)
                        ->helperText('Which registrar is handling this issue\'s allotment.'),
                    TextInput::make('registrar_client_id')
                        ->label('Registrar Client ID')
                        ->helperText("This IPO's own id on the registrar's site — e.g. on MUFG Intime's status checker (in.mpms.mufg.com), pick the company from the dropdown and check the network request for its \"clientid\"; on Bigshare's checker (ipo.bigshareonline.com) it's the numeric \"Company\" value for that issue."),
                ])->columns(2),

            Section::make('Issue Details')
                ->description('Filled in automatically when the IPO is synced from ipoalerts.in.')
                ->icon('heroicon-o-information-circle')
                ->collapsed()
                ->schema([
                    TextInput::make('symbol')->label('Symbol'),
                    Select::make('issue_type')
                        ->label('Board')
                        ->options(['EQ' => 'Mainboard (EQ)', 'SME' => 'SME'])
                        ->native(false),
                    TextInput::make('price_min')->label('Price Band — Low')->numeric()->prefix('₹'),
                    TextInput::make('price_max')->label('Price Band — High')->numeric()->prefix('₹'),
                    TextInput::make('min_amount')->label('Minimum Application')->numeric()->prefix('₹'),
                    TextInput::make('issue_size')->label('Issue Size'),
                    Placeholder::make('gmp_display')
                        ->label('Grey Market Premium')
                        ->content(fn (?Ipo $record) => $record?->gmp === null
                            ? 'No quote'
                            : new HtmlString(
                                '₹'.number_format((float) $record->gmp, 2).
                                ($record->gmp_percent !== null ? ' <span class="text-gray-500">('.number_format((float) $record->gmp_percent, 2).'%)</span>' : '').
                                ($record->estimated_gain_per_lot !== null ? '<br><span class="text-sm text-gray-500">≈ ₹'.number_format($record->estimated_gain_per_lot, 0).' a lot</span>' : '').
                                ($record->gmp_updated_at ? '<br><span class="text-xs text-gray-400">as on '.$record->gmp_updated_at->format('d M Y, H:i').'</span>' : '')
                            ))
                        ->visible(fn (?Ipo $record) => (bool) $record),
                    Placeholder::make('subscription_display')
                        ->label('Subscription')
                        ->content(fn (?Ipo $record) => $record?->subscription_total === null && $record?->subscription_retail === null
                            ? 'Not published yet'
                            : new HtmlString(
                                'Retail <strong>'.number_format((float) $record->subscription_retail, 2).'x</strong>'.
                                ($record->subscription_total !== null ? ' · Total <strong>'.number_format((float) $record->subscription_total, 2).'x</strong>' : '').
                                ($record->subscription_qib !== null ? ' · QIB '.number_format((float) $record->subscription_qib, 2).'x' : '').
                                ($record->subscription_applications ? '<br><span class="text-sm text-gray-500">'.number_format($record->subscription_applications).' applications</span>' : '').
                                ($record->subscription_updated_at ? '<br><span class="text-xs text-gray-400">as on '.$record->subscription_updated_at->format('d M Y, H:i').'</span>' : '')
                            ))
                        ->visible(fn (?Ipo $record) => (bool) $record),
                    TextInput::make('info_url')->label('Info Link')->url()->columnSpanFull(),
                    TextInput::make('prospectus_url')->label('Prospectus')->url()->columnSpanFull(),
                    Textarea::make('about')->label('About the Company')->rows(4)->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->wrap()
                    ->description(fn (Ipo $record) => collect([
                        $record->symbol,
                        $record->issue_type,
                        $record->close_date ? 'Closes '.$record->close_date->format('d M') : null,
                    ])->filter()->implode(' · ') ?: null)
                    ->icon(fn (Ipo $record) => $record->isSynced() ? 'heroicon-m-arrow-path' : null)
                    ->iconColor('gray')
                    ->tooltip(fn (Ipo $record) => $record->isSynced()
                        ? 'From ipoalerts.in, last synced '.$record->synced_at?->diffForHumans()
                        : 'Added by hand'),
                TextColumn::make('open_date')
                    ->date('M d, Y')
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('close_date')
                    ->date('M d, Y')
                    ->sortable(),
                TextColumn::make('price_band')
                    ->label('Price')
                    ->state(fn (Ipo $record) => $record->price_band)
                    ->placeholder('—')
                    ->visibleFrom('sm'),
                TextColumn::make('lot_amount')
                    ->label('1 Lot')
                    ->state(fn (Ipo $record) => $record->lot_amount)
                    ->money('INR')
                    ->description(fn (Ipo $record) => $record->lot_size ? $record->lot_size.' shares' : null)
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('gmp')
                    ->label('GMP')
                    ->state(fn (Ipo $record) => $record->gmp !== null
                        ? '₹'.number_format((float) $record->gmp, 2)
                        : null)
                    ->description(fn (Ipo $record) => $record->gmp_percent !== null
                        ? number_format((float) $record->gmp_percent, 2).'%'
                        : null)
                    ->weight('bold')
                    ->color(fn (Ipo $record) => match (true) {
                        $record->gmp === null => 'gray',
                        (float) $record->gmp > 0 => 'success',
                        (float) $record->gmp < 0 => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (Ipo $record) => $record->estimated_gain_per_lot !== null
                        ? '≈ ₹'.number_format($record->estimated_gain_per_lot, 0).' a lot at grey market price'
                        : null)
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('subscription_retail')
                    ->label('Retail')
                    ->state(fn (Ipo $record) => $record->subscription_retail !== null
                        ? number_format((float) $record->subscription_retail, 2).'x'
                        : null)
                    ->weight('semibold')
                    ->color(fn (Ipo $record) => match (true) {
                        $record->subscription_retail === null => 'gray',
                        (float) $record->subscription_retail >= 3 => 'success',
                        (float) $record->subscription_retail >= 1 => 'warning',
                        default => 'danger',
                    })
                    ->tooltip(fn (Ipo $record) => $record->subscription_updated_at
                        ? 'As on '.$record->subscription_updated_at->format('d M, H:i')
                        : null)
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('subscription_applications')
                    ->label('Applications')
                    ->state(fn (Ipo $record) => $record->subscription_applications
                        ? number_format($record->subscription_applications)
                        : null)
                    ->icon('heroicon-m-user-group')
                    ->iconColor('gray')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('subscription_total')
                    ->label('Total Sub.')
                    ->state(fn (Ipo $record) => $record->subscription_total !== null
                        ? number_format((float) $record->subscription_total, 2).'x'
                        : null)
                    ->description(fn (Ipo $record) => $record->subscription_qib !== null
                        ? 'QIB '.number_format((float) $record->subscription_qib, 2).'x'
                        : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('issue_type')
                    ->label('Board')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state === 'SME' ? 'SME' : 'Mainboard')
                    ->color(fn (?string $state) => $state === 'SME' ? 'warning' : 'info')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('listing_date')
                    ->date('M d, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'upcoming' => 'gray',
                        'open' => 'success',
                        'closed' => 'warning',
                        'allotted' => 'info',
                        'listed' => 'primary',
                        default => 'gray',
                    }),
                TextColumn::make('total_applied')
                    ->label('My Applied')
                    ->state(fn (Ipo $record) => $record->total_applied)
                    ->money('INR')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_returned')
                    ->label('My Returned')
                    ->state(fn (Ipo $record) => $record->total_returned)
                    ->money('INR')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('applications_count')
                    ->counts('applications')
                    ->label('My Applications')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Open issues first — those are the ones you can still act on today —
            // then upcoming, each ordered by how soon it needs a decision (open
            // by closing date, upcoming by opening date). Everything already
            // decided (closed/allotted/listed) follows, newest first. This only
            // applies until the user clicks a column header to sort by hand.
            ->defaultSort(fn (Builder $query) => $query->orderByUrgency())
            ->filters([
                SelectFilter::make('timeline')
                    ->label('Timeline')
                    ->options([
                        'closing_today' => 'Closing Today',
                        'opening_today' => 'Opening Today',
                        'closing_tomorrow' => 'Closing Tomorrow',
                        'opening_tomorrow' => 'Opening Tomorrow',
                    ])
                    ->query(function (Builder $query, array $data) {
                        return match ($data['value'] ?? null) {
                            'closing_today' => $query->closingToday(),
                            'opening_today' => $query->openingToday(),
                            'closing_tomorrow' => $query->closingTomorrow(),
                            'opening_tomorrow' => $query->openingTomorrow(),
                            default => $query,
                        };
                    }),
                SelectFilter::make('issue_type')
                    ->label('Board')
                    ->options(['EQ' => 'Mainboard', 'SME' => 'SME'])
                    ->native(false),
                SelectFilter::make('status')
                    ->options([
                        'upcoming' => 'Upcoming',
                        'open' => 'Open',
                        'closed' => 'Closed',
                        'allotted' => 'Allotted',
                        'listed' => 'Listed',
                    ])
                    ->multiple(),
                Filter::make('has_gmp')
                    ->label('Grey market premium only')
                    ->query(fn (Builder $query) => $query->whereNotNull('gmp')->where('gmp', '>', 0))
                    ->toggle(),
            ])
            ->actions([
                Action::make('checkAllotment')
                    ->label('Check Allotment')
                    ->icon('heroicon-o-magnifying-glass-circle')
                    ->color('success')
                    ->tooltip('Check allotment with registrar')
                    ->visible(fn (Ipo $record) => $record->applications()->exists())
                    ->requiresConfirmation()
                    ->modalHeading(fn (Ipo $record) => "Check Allotment: {$record->name}")
                    ->modalDescription(fn (Ipo $record) => "This will look up allotment for all pending applications of {$record->name} directly with the registrar and update their status.")
                    ->action(function (Ipo $record, AllotmentCheckService $service) {
                        if (! $record->canAutoCheckAllotment()) {
                            Notification::make()
                                ->title('Registrar Not Configured')
                                ->body('Please edit this IPO and set Registrar & Registrar Client ID first.')
                                ->warning()
                                ->send();
                            return;
                        }

                        $pending = $record->applications()->where('status', 'applied')->get();

                        if ($pending->isEmpty()) {
                            Notification::make()
                                ->title('No Pending Applications')
                                ->body('All applications for this IPO have already been checked or updated.')
                                ->info()
                                ->send();
                            return;
                        }

                        $counts = $service->checkManyDirect($pending);

                        $summary = "Checked {$counts['total']} application(s): "
                            ."{$counts['allotted']} Allotted · "
                            ."{$counts['refunded']} Refunded";

                        if ($counts['not_found'] > 0) $summary .= " · {$counts['not_found']} Not Found";
                        if ($counts['skipped'] > 0) $summary .= " · {$counts['skipped']} Skipped";
                        if ($counts['error'] > 0) {
                            $summary .= " · {$counts['error']} Errors";
                            if (! empty($counts['errors'])) {
                                $summary .= ' ('.implode(', ', array_slice($counts['errors'], 0, 2)).')';
                            }
                        }

                        Notification::make()
                            ->title('Allotment Check Completed')
                            ->body($summary)
                            ->color($counts['allotted'] > 0 ? 'success' : ($counts['error'] > 0 ? 'warning' : 'info'))
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ApplicationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIpos::route('/'),
            'create' => Pages\CreateIpo::route('/create'),
            'edit' => Pages\EditIpo::route('/{record}/edit'),
        ];
    }
}
