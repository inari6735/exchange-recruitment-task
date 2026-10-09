<?php

declare(strict_types=1);

namespace App\Tests\Dto\Request;

use App\Dto\Request\TransferRequest;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class TransferRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testValidRequestAndTypedGetters(): void
    {
        $request = new TransferRequest('12', 34, 100.5);

        self::assertCount(0, $this->validator->validate($request));
        self::assertSame(12, $request->fromWalletId());
        self::assertSame(34, $request->toWalletId());
        self::assertSame('100.5', $request->amount());
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('firstErrorProvider')]
    public function testFirstViolationMatchesLegacyOrder(array $fields, string $expectedMessage): void
    {
        $violations = $this->validator->validate(new TransferRequest(...$fields));

        self::assertGreaterThan(0, \count($violations));
        self::assertSame($expectedMessage, $violations[0]->getMessage());
    }

    public static function firstErrorProvider(): Generator
    {
        yield 'everything missing' => [[], 'Missing required field: fromWalletId.'];
        yield 'toWalletId missing' => [['fromWalletId' => 1, 'amount' => '10'], 'Missing required field: toWalletId.'];
        yield 'amount missing' => [['fromWalletId' => 1, 'toWalletId' => 2], 'Missing required field: amount.'];
        yield 'invalid amount' => [['fromWalletId' => 1, 'toWalletId' => 2, 'amount' => '-1'], 'Amount must be a positive number.'];
        yield 'invalid fromWalletId' => [['fromWalletId' => 'abc', 'toWalletId' => 2, 'amount' => '10'], 'fromWalletId must be a positive integer.'];
        yield 'invalid toWalletId' => [['fromWalletId' => 1, 'toWalletId' => 'x', 'amount' => '10'], 'toWalletId must be a positive integer.'];
    }

    public function testMissingFieldBeatsInvalidFormatOfAnotherField(): void
    {
        $violations = $this->validator->validate(new TransferRequest(fromWalletId: 'abc', amount: '-1'));

        self::assertSame('Missing required field: toWalletId.', $violations[0]->getMessage());
    }

    #[DataProvider('invalidIdProvider')]
    public function testRejectsInvalidWalletId(mixed $id): void
    {
        $violations = $this->validator->validate(new TransferRequest($id, 2, '10'));

        self::assertCount(1, $violations);
        self::assertSame('fromWalletId must be a positive integer.', $violations[0]->getMessage());
    }

    public static function invalidIdProvider(): Generator
    {
        yield 'letters' => ['abc'];
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'float' => [1.5];
        yield 'float with zero fraction' => [2.0];
        yield 'boolean' => [true];
        yield 'leading zero' => ['012'];
        yield 'array' => [[1]];
        yield 'trailing newline' => ["12\n"];
    }
}
