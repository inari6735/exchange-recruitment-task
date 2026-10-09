<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\TransactionManagerInterface;

/**
 * Runs the operation directly; counts calls so unit tests can assert a single transaction boundary.
 */
final class ImmediateTransactionManager implements TransactionManagerInterface
{
    public int $calls = 0;

    public function transactional(callable $operation): mixed
    {
        ++$this->calls;

        return $operation();
    }
}
