<?php

namespace App\Services\AllotmentCheck;

/** What happened when checking one application, for the bulk-action summary. */
final class AllotmentCheckOutcome
{
    private function __construct(
        public readonly string $status, // updated | not_found | skipped | error
        public readonly ?string $message = null,
    ) {}

    public static function updated(): self
    {
        return new self('updated');
    }

    public static function notFound(): self
    {
        return new self('not_found');
    }

    public static function skipped(string $reason): self
    {
        return new self('skipped', $reason);
    }

    public static function error(string $message): self
    {
        return new self('error', $message);
    }
}
