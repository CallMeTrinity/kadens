<?php

declare(strict_types=1);

namespace App\Service;

/** La clé Intervals.icu est refusée (401/403) : en coller une autre, réessayer n'y changera rien. */
final class IntervalsAuthException extends \RuntimeException
{
}
