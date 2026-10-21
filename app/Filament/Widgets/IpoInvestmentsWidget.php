<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\IpoApplicationResource;
use App\Models\Ipo;
use App\Services\AllotmentCheck\AllotmentCheckService;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class IpoInvestmentsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'IPO-wise Investments & Status (કયા IPO માં કેટલા રોક્યા છે)';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Ipo::query()
                    ->whereHas('applications')
                    ->with(['applications'])
                    ->orderByDesc('id')
            )
            ->columns([
                TextColumn::make('name')
                    ->label('IPO Name')
                    ->weight('bold')
                    ->description(fn (Ipo $record) => collect([
                        $record->symbol,
                        $record->issue_type === 'SME' ? 'SME' : 'Mainboard',
                        $record->allotment_date ? 'Allotment: ' . $record->allotment_date->format('d M Y') : null,
                    ])->filter()->implode(' · ')),

                TextColumn::make('applications_count')
                    ->label('Applications')
                    ->badge()
                    ->color('primary')
                    ->state(fn (Ipo $record) => $record->applications->count() . ' Apps')
                    ->description(function (Ipo $record) {
                        $applied = $record->applications->where('status', 'applied')->count();
                        $allotted = $record->applications->whereIn('status', ['allotted', 'partial_allotted'])->count();
                        $refunded = $record->applications->where('status', 'refunded')->count();
                        $sold = $record->applications->where('status', 'sold')->count();

                        $parts = [];
                        if ($applied > 0) $parts[] = "{$applied} pending";
                        if ($allotted > 0) $parts[] = "{$allotted} allotted";
                        if ($sold > 0) $parts[] = "{$sold} sold";
                        if ($refunded > 0) $parts[] = "{$refunded} refunded";

                        return implode(' · ', $parts) ?: null;
                    }),

                TextColumn::make('total_invested')
                    ->label('Total Invested (રોકેલી રકમ)')
                    ->state(fn (Ipo $record) => (float) $record->applications->sum('amount'))
                    ->money('INR')
                    ->weight('bold')
                    ->color('primary'),

                TextColumn::make('total_returned')
                    ->label('Returned (પાછા મળ્યા)')
                    ->state(function (Ipo $record) {
                        $ret = (float) $record->applications->sum('return_amount');
                        return $ret > 0 ? $ret : null;
                    })
                    ->money('INR')
                    ->placeholder('—'),

                TextColumn::make('profit')
                    ->label('Profit (નફો)')
                    ->state(function (Ipo $record) {
                        $sold = $record->applications->where('status', 'sold');
                        if ($sold->isEmpty()) return null;
                        return (float) $sold->sum(fn ($a) => (float) $a->profit);
                    })
                    ->money('INR')
                    ->placeholder('—')
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 0 ? 'success' : 'danger'))
                    ->weight('semibold'),

                TextColumn::make('allotment_status')
                    ->label('Result')
                    ->badge()
                    ->state(function (Ipo $record) {
                        $apps = $record->applications;
                        if ($apps->isEmpty()) return 'None';
                        if ($apps->contains(fn ($a) => in_array($a->status, ['allotted', 'partial_allotted', 'sold']))) return 'Allotted';
                        if ($apps->every(fn ($a) => $a->status === 'refunded')) return 'Refunded';
                        return 'Pending';
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Allotted' => 'success',
                        'Refunded' => 'gray',
                        'Pending' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->actions([
                Action::make('checkAllotment')
                    ->label('Check Allotment')
                    ->icon('heroicon-o-magnifying-glass-circle')
                    ->color('success')
                    ->button()
                    ->requiresConfirmation()
                    ->modalHeading(fn (Ipo $record) => "Check Allotment: {$record->name}")
                    ->modalDescription(fn (Ipo $record) => $record->canAutoCheckAllotment()
                        ? "Look up allotment for all pending applications of {$record->name} with registrar."
                        : "Registrar is not configured for this IPO yet. Please select the Registrar and Company below:"
                    )
                    ->form(fn (Ipo $record, AllotmentCheckService $service) => ! $record->canAutoCheckAllotment() ? [
                        Select::make('registrar')
                            ->label('Registrar')
                            ->options(AllotmentCheckService::registrarOptions())
                            ->required()
                            ->live()
                            ->native(false)
                            ->afterStateUpdated(function (Set $set, ?string $state) use ($service, $record) {
                                if ($state) {
                                    $matchedId = $service->matchCompanyId($state, $record->name);
                                    if ($matchedId) {
                                        $set('registrar_client_id', $matchedId);
                                    }
                                }
                            }),
                        Select::make('registrar_client_id')
                            ->label('Company on Registrar')
                            ->placeholder('Select Company...')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->options(function (Get $get) use ($service) {
                                $reg = $get('registrar');
                                return $reg ? $service->getRegistrarCompanies($reg) : [];
                            })
                            ->default(fn (Get $get) => $get('registrar') ? $service->matchCompanyId($get('registrar'), $record->name) : null)
                            ->helperText('Select this company from the registrar\'s live list.'),
                    ] : [])
                    ->action(function (Ipo $record, array $data, AllotmentCheckService $service) {
                        if (! $record->canAutoCheckAllotment()) {
                            if (! empty($data['registrar']) && ! empty($data['registrar_client_id'])) {
                                $record->update([
                                    'registrar' => $data['registrar'],
                                    'registrar_client_id' => $data['registrar_client_id'],
                                ]);
                            } else {
                                Notification::make()
                                    ->title('Registrar Not Configured')
                                    ->body('Please select Registrar and Company first.')
                                    ->warning()
                                    ->send();
                                return;
                            }
                        }

                        $pending = $record->applications()->where('status', 'applied')->get();

                        if ($pending->isEmpty()) {
                            Notification::make()
                                ->title('No Pending Applications')
                                ->body('All applications for this IPO have already been checked.')
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

                Action::make('view_applications')
                    ->label('View Apps')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (Ipo $record) => IpoApplicationResource::getUrl('index', [
                        'tableFilters' => ['ipo_id' => ['value' => $record->id]],
                    ])),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
