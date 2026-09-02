<?php

namespace App\Filament\Source\Concerns;

use App\Models\Source;

/**
 * Every page and widget in the source portal reads exactly one source's data:
 * the one that is signed in. Nothing here ever takes a source id from the URL
 * or the request, so there is no id to tamper with.
 */
trait InteractsWithSource
{
    protected function source(): Source
    {
        /** @var Source $source */
        $source = auth('source')->user();

        return $source;
    }
}
