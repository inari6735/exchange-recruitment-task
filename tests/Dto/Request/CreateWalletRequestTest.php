<?php

declare(strict_types=1);

namespace App\Tests\Dto\Request;

use App\Dto\Request\CreateWalletRequest;
use App\Enum\Currency;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CreateWalletRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testValidCurrency(): void
    {
        $request = new CreateWalletRequest('EUR');

        self::assertCount(0, $this->validator->validate($request));
        self::assertSame(Currency::EUR, $request->currency());
    }

    public function testMissingCurrency(): void
    {
        $violations = $this->validator->validate(new CreateWalletRequest());

        self::assertCount(1, $violations);
        self::assertSame('Missing required field: currency.', $violations[0]->getMessage());
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidCurrency(mixed $currency): void
    {
        $violations = $this->validator->validate(new CreateWalletRequest($currency));

        self::assertCount(1, $violations);
        self::assertSame('Invalid currency.', $violations[0]->getMessage());
    }

    public static function invalidProvider(): Generator
    {
        yield 'unknown' => ['XYZ'];
        yield 'lowercase' => ['pln'];
        yield 'empty' => [''];
        yield 'number' => [123];
        yield 'array' => [['PLN']];
        yield 'boolean' => [true];
    }

    public function testCurrencyCodesListAllCurrencies(): void
    {
        self::assertSame(['PLN', 'EUR', 'USD', 'GBP', 'JPY', 'CHF', 'HUF'], CreateWalletRequest::currencyCodes());
    }
}
