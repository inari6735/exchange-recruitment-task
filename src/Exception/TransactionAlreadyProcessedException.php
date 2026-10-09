<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class TransactionAlreadyProcessedException extends RuntimeException
{
    public function __construct(int $transactionId)
    {
        parent::__construct(sprintf('Transaction %d was already processed.', $transactionId));
    }
}
