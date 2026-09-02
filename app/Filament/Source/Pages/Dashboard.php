<?php

namespace App\Filament\Source\Pages;

use App\Filament\Source\Concerns\InteractsWithSource;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    use InteractsWithSource;

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?int $navigationSort = -2;

    public function getTitle(): string
    {
        return 'Hello, '.$this->source()->name;
    }

    public function getSubheading(): ?string
    {
        return 'Everything applied with your money, and where it stands today.';
    }
}
