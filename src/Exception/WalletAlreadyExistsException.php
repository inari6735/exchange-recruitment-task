<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\Currency;
use RuntimeException;

final class WalletAlreadyExistsException extends RuntimeException
{
    /**
     * The message is returned to API clients, so it must not contain internal identifiers (e.g. the user id).
     */
    public function __construct(Currency $currency)
    {
        parent::__construct(sprintf('Wallet in currency %s already exists.', $currency->value));
    }
}
