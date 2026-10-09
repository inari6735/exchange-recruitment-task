<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

readonly class DbalTransactionManager implements TransactionManagerInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->connection->transactional($operation(...));
    }
}
