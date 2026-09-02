<?php

namespace App\Services\AllotmentCheck;

/**
 * One registrar's public "check your allotment" tool, automated. Every checker
 * takes the registrar's own per-issue id (however that registrar identifies the
 * IPO) plus a PAN, and returns what that PAN was allotted — or null when the
 * registrar has no record for that PAN on that issue.
 */
interface AllotmentChecker
{
    /** @throws AllotmentCheckException when the registrar's tool itself failed (not just "no match") */
    public function check(string $registrarClientId, string $pan): ?AllotmentCheckResult;
}
