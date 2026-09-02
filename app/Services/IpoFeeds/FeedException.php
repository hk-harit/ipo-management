<?php

namespace App\Services\IpoFeeds;

use RuntimeException;

/** Anything that stopped one feed handing back usable data. */
class FeedException extends RuntimeException
{
}
