<?php

namespace App\Filament\Pages;

use App\Filament\Support\IpoPicker;
use App\Models\Account;
use App\Models\Applicant;
use App\Models\IpoApplication;
use App\Models\Source;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class BulkIpoApply extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'IPO Management';

    protected static ?string $navigationLabel = 'Bulk Apply';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.bulk-ipo-apply';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'applied_date' => now()->toDateString(),
            'rows' => [[]],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                IpoPicker::select()->columnSpan(1),
                DatePicker::make('applied_date')
                    ->label('Applied Date')
                    ->required()
                    ->default(now())
                    ->columnSpan(1),
                IpoPicker::summary(),
                Repeater::make('rows')
                    ->label('Applications')
                    ->schema([
                        Select::make('applicant_id')
                            ->label('Applicant (ડીમેટ)')
                            ->options(fn () => Applicant::query()->orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->searchable(),
                        Select::make('account_id')
                            ->label('Payment Account (બ્લોક)')
                            ->options(fn () => \App\Models\Account::hasSubAccountColumn() ? \App\Models\Account::query()->where('is_sub_account', false)->orderBy('name')->pluck('name', 'id') : \App\Models\Account::query()->orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->live(),
                        Select::make('sub_account_id')
                            ->label('Sub-Account')
                            ->options(function (\Filament\Forms\Get $get) {
                                if (! \App\Models\Account::hasSubAccountColumn()) {
                                    return [];
                                }
                                $accId = $get('account_id');
                                if (! $accId) {
                                    return \App\Models\Account::where('is_sub_account', true)->pluck('name', 'id');
                                }
                                return \App\Models\Account::where('is_sub_account', true)->where('parent_id', $accId)->pluck('name', 'id');
                            })
                            ->visible(fn () => \App\Models\Account::hasSubAccountColumn())
                            ->placeholder('Direct')
                            ->searchable(),
                        Select::make('funding_type')
                            ->label('Funding')
                            ->options([
                                'direct' => 'Direct (સીધા)',
                                'transferred' => 'Transfer (મોકલીને)',
                            ])
                            ->default('direct')
                            ->live(),
                        Select::make('funding_account_id')
                            ->label('From Account')
                            ->options(fn () => Account::query()->orderBy('name')->pluck('name', 'id'))
                            ->visible(fn (\Filament\Forms\Get $get) => $get('funding_type') === 'transferred')
                            ->searchable(),
                        Select::make('source_id')
                            ->label('Source (optional)')
                            ->options(fn () => Source::query()->orderBy('name')->pluck('name', 'id'))
                            ->searchable(),
                        TextInput::make('amount')
                            ->numeric()
                            ->prefix('₹')
                            ->required(),
                    ])
                    ->columns([
                        'default' => 1,
                        'sm' => 2,
                        'md' => 3,
                        'xl' => 7,
                    ])
                    ->addActionLabel('Add another applicant (+)')
                    ->defaultItems(1)
                    ->reorderable(false)
                    ->columnSpanFull(),
            ])
            ->columns([
                'default' => 1,
                'md' => 2,
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $state = $this->form->getState();

        $rows = collect($state['rows'] ?? [])->filter(
            fn ($row) => filled($row['applicant_id'] ?? null) && filled($row['account_id'] ?? null) && filled($row['amount'] ?? null)
        );

        if ($rows->isEmpty()) {
            Notification::make()
                ->title('Add at least one applicant row')
                ->danger()
                ->send();

            return;
        }

        $rows->each(function (array $row) use ($state) {
            $data = [
                'ipo_id' => $state['ipo_id'],
                'applicant_id' => $row['applicant_id'],
                'account_id' => $row['account_id'],
                'source_id' => $row['source_id'] ?? null,
                'amount' => $row['amount'],
                'applied_date' => $state['applied_date'],
                'status' => 'applied',
            ];

            if (\App\Models\Account::hasSubAccountColumn() && ! empty($row['sub_account_id'])) {
                $data['sub_account_id'] = $row['sub_account_id'];
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn('ipo_applications', 'funding_type')) {
                $data['funding_type'] = $row['funding_type'] ?? 'direct';
                $data['funding_account_id'] = ($row['funding_type'] ?? 'direct') === 'transferred' ? ($row['funding_account_id'] ?? null) : null;
            }

            IpoApplication::create($data);
        });

        Notification::make()
            ->title($rows->count().' application(s) created')
            ->success()
            ->send();

        $this->form->fill([
            'ipo_id' => $state['ipo_id'],
            'applied_date' => $state['applied_date'],
            'rows' => [[]],
        ]);
    }
}
