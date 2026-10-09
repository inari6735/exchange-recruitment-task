<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class WalletHasPendingTransfersException extends RuntimeException
{
    public function __construct(int $walletId)
    {
        parent::__construct(sprintf('Wallet %d has pending transfers.', $walletId));
    }
}
