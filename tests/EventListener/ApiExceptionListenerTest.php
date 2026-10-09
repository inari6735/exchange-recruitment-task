<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Enum\Currency;
use App\EventListener\ApiExceptionListener;
use App\Exception\DepositLimitExceededException;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletHasPendingTransfersException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\ValueObject\Money;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

class ApiExceptionListenerTest extends TestCase
{
    #[DataProvider('domainExceptionProvider')]
    public function testMapsDomainExceptions(Throwable $exception, int $status): void
    {
        $event = $this->dispatch($exception);

        self::assertSame($status, $event->getResponse()?->getStatusCode());
        self::assertSame(['error' => $exception->getMessage()], $this->body($event));
    }

    public static function domainExceptionProvider(): Generator
    {
        yield 'not found' => [new WalletNotFoundException(7), 404];
        yield 'already exists' => [new WalletAlreadyExistsException(Currency::PLN), 409];
        yield 'invalid money' => [InvalidMoneyAmountException::tooManyDecimalPlaces(Currency::PLN), 400];
        yield 'deposit limit' => [new DepositLimitExceededException(Money::of('2500', Currency::EUR)), 400];
        yield 'same wallet' => [new SameWalletTransferException(), 400];
        yield 'blocked' => [new WalletBlockedException(3), 422];
        yield 'insufficient funds' => [new InsufficientFundsException(3), 422];
        yield 'not empty' => [new WalletNotEmptyException(3), 422];
        yield 'pending transfers' => [new WalletHasPendingTransfersException(3), 422];
    }

    public function testValidationFailureReturnsFirstViolation(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('Invalid currency.', null, [], null, 'currency', 'XYZ'),
            new ConstraintViolation('Second problem.', null, [], null, 'other', 'x'),
        ]);
        $exception = new HttpException(400, "Invalid currency.\nSecond problem.", new ValidationFailedException(new stdClass(), $violations));

        $event = $this->dispatch($exception);

        self::assertSame(400, $event->getResponse()?->getStatusCode());
        self::assertSame(['error' => 'Invalid currency.'], $this->body($event));
    }

    public function testValidationFailureWithoutPayloadObjectIsInvalidJson(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('This value should be of type array.', null, [], null, '', 5),
        ]);
        $exception = new HttpException(400, 'x', new ValidationFailedException(null, $violations));

        self::assertSame(['error' => 'Invalid JSON body.'], $this->body($this->dispatch($exception)));
    }

    public function testMalformedJsonBody(): void
    {
        $exception = new BadRequestHttpException('Request payload contains invalid "json" data.', new NotEncodableValueException('Syntax error'));

        $event = $this->dispatch($exception);

        self::assertSame(400, $event->getResponse()?->getStatusCode());
        self::assertSame(['error' => 'Invalid JSON body.'], $this->body($event));
    }

    public function testEmptyBody(): void
    {
        self::assertSame(['error' => 'Invalid JSON body.'], $this->body($this->dispatch(HttpException::fromStatusCode(400))));
    }

    public function testUnsupportedMediaType(): void
    {
        $event = $this->dispatch(new UnsupportedMediaTypeHttpException('Unsupported format.'));

        self::assertSame(415, $event->getResponse()?->getStatusCode());
        self::assertSame(['error' => 'Unsupported content type, expected application/json.'], $this->body($event));
    }

    public function testNotFound(): void
    {
        $event = $this->dispatch(new NotFoundHttpException('No route found for "POST /api/wallets/abc/deposit"'));

        self::assertSame(404, $event->getResponse()?->getStatusCode());
        self::assertSame(['error' => 'Not found.'], $this->body($event));
    }

    public function testOtherHttpExceptionUsesStatusTextAndKeepsHeaders(): void
    {
        $event = $this->dispatch(new MethodNotAllowedHttpException(['GET', 'POST']));

        self::assertSame(405, $event->getResponse()?->getStatusCode());
        self::assertSame(['error' => 'Method Not Allowed'], $this->body($event));
        self::assertSame('GET, POST', $event->getResponse()?->headers->get('Allow'));
    }

    public function testResponseIsJson(): void
    {
        $event = $this->dispatch(new WalletNotFoundException(7));

        self::assertSame('application/json', $event->getResponse()?->headers->get('Content-Type'));
    }

    public function testIgnoresPathsOutsideApi(): void
    {
        $event = $this->dispatch(new WalletNotFoundException(7), '/health');

        self::assertNull($event->getResponse());
    }

    public function testLeavesUnknownExceptionsToSymfony(): void
    {
        $event = $this->dispatch(new RuntimeException('boom'));

        self::assertNull($event->getResponse());
    }

    private function dispatch(Throwable $exception, string $path = '/api/wallets'): ExceptionEvent
    {
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path, 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );

        new ApiExceptionListener()($event);

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ExceptionEvent $event): array
    {
        return json_decode((string) $event->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
