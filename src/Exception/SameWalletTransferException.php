<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class SameWalletTransferException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cannot transfer to the same wallet.');
    }
}
