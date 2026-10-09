<?php

declare(strict_types=1);

namespace App\Repository;

interface TransactionManagerInterface
{
    /**
     * Runs the operation in a database transaction: commits on success, rolls back and rethrows on exception.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
