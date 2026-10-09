<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Enum\Currency;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Raw JSON values are kept as mixed, so wrong types produce our messages instead of deserialization errors.
 * Presence of every field is checked before any format (the API reports the first problem).
 */
#[Assert\GroupSequence(['Presence', 'CreateWalletRequest'])]
final readonly class CreateWalletRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Missing required field: currency.', groups: ['Presence'])]
        #[Assert\Choice(callback: [self::class, 'currencyCodes'], message: 'Invalid currency.')]
        public mixed $currency = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function currencyCodes(): array
    {
        return array_map(static fn (Currency $currency): string => $currency->value, Currency::cases());
    }

    public function currency(): Currency
    {
        return Currency::from($this->currency);
    }
}
