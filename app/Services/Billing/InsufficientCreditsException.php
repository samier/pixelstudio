<?php

namespace App\Services\Billing;

use App\Support\Money;
use RuntimeException;

final class InsufficientCreditsException extends RuntimeException
{
    public function __construct(public readonly int $requiredNanos, public readonly int $availableNanos)
    {
        parent::__construct(sprintf('This generation can cost up to $%s but your balance is $%s.',
            Money::format($requiredNanos), Money::format($availableNanos)));
    }
}
