<?php

namespace App\Services\AllotmentCheck;

/** One registrar's answer for one PAN on one IPO. */
final class AllotmentCheckResult
{
    public function __construct(
        public readonly ?int $sharesApplied,
        public readonly int $sharesAllotted,
        public readonly ?float $refundAmount,
        public readonly ?string $refundReference,
        public readonly ?string $companyName = null,
    ) {}

    public function isAllotted(): bool
    {
        return $this->sharesAllotted > 0;
    }

    public function isFullyAllotted(): bool
    {
        return $this->sharesApplied !== null && $this->sharesAllotted >= $this->sharesApplied;
    }
}
