<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\DepositLimitExceededException;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

use function is_object;

/**
 * Turns domain and HTTP exceptions raised under /api into {"error": "..."} JSON responses.
 * Unknown exceptions are left to Symfony (they stay 500).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class ApiExceptionListener
{
    /** HTTP knowledge stays in the HTTP layer; domain exceptions don't know about status codes. */
    private const array DOMAIN_EXCEPTION_STATUS = [
        WalletNotFoundException::class => Response::HTTP_NOT_FOUND,
        WalletAlreadyExistsException::class => Response::HTTP_CONFLICT,
        InvalidMoneyAmountException::class => Response::HTTP_BAD_REQUEST,
        DepositLimitExceededException::class => Response::HTTP_BAD_REQUEST,
        SameWalletTransferException::class => Response::HTTP_BAD_REQUEST,
        WalletBlockedException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
        InsufficientFundsException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
    ];

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $response = $this->toResponse($event->getThrowable());
        if (null !== $response) {
            $event->setResponse($response);
        }
    }

    private function toResponse(Throwable $exception): ?JsonResponse
    {
        foreach (self::DOMAIN_EXCEPTION_STATUS as $class => $status) {
            if ($exception instanceof $class) {
                return self::error($exception->getMessage(), $status);
            }
        }

        if (!$exception instanceof HttpExceptionInterface) {
            return null;
        }

        $status = $exception->getStatusCode();
        $previous = $exception->getPrevious();

        $message = match (true) {
            $previous instanceof ValidationFailedException && is_object($previous->getValue()) => (string) $previous->getViolations()->get(0)->getMessage(),
            $exception instanceof UnsupportedMediaTypeHttpException => 'Unsupported content type, expected application/json.',
            Response::HTTP_BAD_REQUEST === $status => 'Invalid JSON body.',
            $exception instanceof NotFoundHttpException => 'Not found.',
            default => Response::$statusTexts[$status] ?? 'Error',
        };

        return self::error($message, $status, $exception->getHeaders());
    }

    /**
     * @param array<string, string> $headers
     */
    private static function error(string $message, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status, $headers);
    }
}
