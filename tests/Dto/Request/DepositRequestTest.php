<?php

declare(strict_types=1);

namespace App\Tests\Dto\Request;

use App\Dto\Request\DepositRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class DepositRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testValidAmount(): void
    {
        $request = new DepositRequest(500);

        self::assertCount(0, $this->validator->validate($request));
        self::assertSame('500', $request->amount());
    }

    public function testMissingAmount(): void
    {
        $violations = $this->validator->validate(new DepositRequest());

        self::assertCount(1, $violations);
        self::assertSame('Missing required field: amount.', $violations[0]->getMessage());
    }

    public function testInvalidAmount(): void
    {
        $violations = $this->validator->validate(new DepositRequest('-50'));

        self::assertCount(1, $violations);
        self::assertSame('Amount must be a positive number.', $violations[0]->getMessage());
    }
}
