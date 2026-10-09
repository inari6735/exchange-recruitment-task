<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Currency;
use App\ValueObject\Money;
use InvalidArgumentException;

final readonly class TransactionLimits
{
    /** @var array<string, Money> */
    private array $antiFraudThresholds;

    /** @var array<string, Money> */
    private array $depositLimits;

    /**
     * @param array<string, string|int> $antiFraudThresholds amount per currency code; a transfer above it goes to fraud review
     * @param array<string, string|int> $depositLimits       maximum single deposit per currency code
     */
    public function __construct(array $antiFraudThresholds, array $depositLimits)
    {
        $this->antiFraudThresholds = self::toMoneyMap($antiFraudThresholds, 'anti-fraud threshold');
        $this->depositLimits = self::toMoneyMap($depositLimits, 'deposit limit');
    }

    public function antiFraudThreshold(Currency $currency): Money
    {
        return $this->antiFraudThresholds[$currency->value];
    }

    public function depositLimit(Currency $currency): Money
    {
        return $this->depositLimits[$currency->value];
    }

    /**
     * @param array<string, string|int> $values
     *
     * @return array<string, Money>
     */
    private static function toMoneyMap(array $values, string $name): array
    {
        $map = [];

        foreach (Currency::cases() as $currency) {
            if (!isset($values[$currency->value])) {
                throw new InvalidArgumentException(sprintf('Missing %s for %s.', $name, $currency->value));
            }

            $limit = Money::of((string) $values[$currency->value], $currency);
            if (!$limit->isGreaterThan(Money::zero($currency))) {
                throw new InvalidArgumentException(sprintf('%s for %s must be positive.', ucfirst($name), $currency->value));
            }

            $map[$currency->value] = $limit;
        }

        return $map;
    }
}
