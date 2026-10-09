<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Validator\PositiveAmount;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Wallet ids: a positive JSON integer or a string of digits. Amount: a positive decimal string or JSON number.
 * Presence of every field is checked before any format, in field order (the API reports the first problem).
 */
#[Assert\GroupSequence(['Presence', 'TransferRequest'])]
final readonly class TransferRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Missing required field: fromWalletId.', groups: ['Presence'])]
        #[Assert\Sequentially([
            new Assert\Type(type: ['integer', 'string'], message: 'fromWalletId must be a positive integer.'),
            new Assert\Regex(pattern: '/^[1-9]\d*$/D', message: 'fromWalletId must be a positive integer.'),
        ])]
        public mixed $fromWalletId = null,
        #[Assert\NotNull(message: 'Missing required field: toWalletId.', groups: ['Presence'])]
        #[Assert\Sequentially([
            new Assert\Type(type: ['integer', 'string'], message: 'toWalletId must be a positive integer.'),
            new Assert\Regex(pattern: '/^[1-9]\d*$/D', message: 'toWalletId must be a positive integer.'),
        ])]
        public mixed $toWalletId = null,
        #[Assert\NotNull(message: 'Missing required field: amount.', groups: ['Presence'])]
        #[PositiveAmount]
        public mixed $amount = null,
    ) {
    }

    public function fromWalletId(): int
    {
        return (int) $this->fromWalletId;
    }

    public function toWalletId(): int
    {
        return (int) $this->toWalletId;
    }

    public function amount(): string
    {
        return (string) AmountInput::toDecimalString($this->amount);
    }
}
