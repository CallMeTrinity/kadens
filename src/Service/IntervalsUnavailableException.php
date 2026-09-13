<?php

declare(strict_types=1);

namespace App\Service;

/** Intervals.icu injoignable ou en erreur (réseau, délai, 5xx, 429) : réessayer plus tard. */
final class IntervalsUnavailableException extends \RuntimeException
{
}
