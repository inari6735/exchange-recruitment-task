<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Validator\PositiveAmount;
use Symfony\Component\Validator\Constraints as Assert;

#[Assert\GroupSequence(['Presence', 'DepositRequest'])]
final readonly class DepositRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Missing required field: amount.', groups: ['Presence'])]
        #[PositiveAmount]
        public mixed $amount = null,
    ) {
    }

    public function amount(): string
    {
        return (string) AmountInput::toDecimalString($this->amount);
    }
}
