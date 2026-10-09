<?php

declare(strict_types=1);

namespace App\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * A positive decimal amount given as a string or a JSON number. Currency precision is checked later by Money::of().
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
final class PositiveAmount extends Constraint
{
    public function __construct(
        public string $message = 'Amount must be a positive number.',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(groups: $groups, payload: $payload);
    }
}
