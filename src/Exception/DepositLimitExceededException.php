<?php

declare(strict_types=1);

namespace App\Exception;

use App\ValueObject\Money;
use RuntimeException;

final class DepositLimitExceededException extends RuntimeException
{
    public function __construct(Money $limit)
    {
        parent::__construct(sprintf('Amount cannot exceed %s %s.', $limit->toString(), $limit->getCurrency()->value));
    }
}
