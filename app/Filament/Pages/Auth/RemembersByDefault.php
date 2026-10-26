<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Component;

/**
 * "Keep me signed in" starts ticked. This is a phone app: being asked to log in again
 * every couple of hours would make it useless. (The remember cookie outlives the
 * session, so the app opens straight in even after the session has expired.)
 */
trait RemembersByDefault
{
    protected function getRememberFormComponent(): Component
    {
        return Checkbox::make('remember')
            ->label('Keep me signed in (મને લૉગિન રાખો)')
            ->default(true);
    }
}
