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
                            ->label('Applicant')
                            ->options(fn () => Applicant::query()->orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->searchable(),
                        Select::make('account_id')
                            ->label('Funded From')
                            ->options(fn () => Account::query()->orderBy('name')->pluck('name', 'id'))
                            ->required()
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
                    ->columns(4)
                    ->addActionLabel('Add another applicant')
                    ->defaultItems(1)
                    ->reorderable(false)
                    ->columnSpanFull(),
            ])
            ->columns(2)
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
            IpoApplication::create([
                'ipo_id' => $state['ipo_id'],
                'applicant_id' => $row['applicant_id'],
                'account_id' => $row['account_id'],
                'source_id' => $row['source_id'] ?? null,
                'amount' => $row['amount'],
                'applied_date' => $state['applied_date'],
                'status' => 'applied',
            ]);
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
