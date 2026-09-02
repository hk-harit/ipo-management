<?php

namespace App\Services\AllotmentCheck;

use RuntimeException;

/** The registrar's tool itself failed (bad response, no token, timeout) — distinct from a clean "no match". */
class AllotmentCheckException extends RuntimeException {}
