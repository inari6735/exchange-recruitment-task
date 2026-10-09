<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Request\CreateWalletRequest;
use App\Dto\Request\DepositRequest;
use App\Dto\Request\TransferRequest;
use App\Dto\TransactionResponse;
use App\Dto\WalletResponse;
use App\Entity\User;
use App\Repository\WalletRepositoryInterface;
use App\Service\DepositService;
use App\Service\TransferService;
use App\Service\WalletService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Request validation lives in the request DTOs; errors become JSON in App\EventListener\ApiExceptionListener.
 */
#[Route('/api/wallets')]
final class WalletController extends AbstractController
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly WalletRepositoryInterface $walletRepository,
        private readonly TransferService $transferService,
        private readonly DepositService $depositService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $wallets = $this->walletRepository->findByUserId($user->getIdNotNull());

        return new JsonResponse(array_map(static fn ($w) => new WalletResponse($w), $wallets));
    }

    #[Route('', methods: ['POST'])]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        CreateWalletRequest $payload,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $wallet = $this->walletService->createWallet($user->getIdNotNull(), $payload->currency());

        return new JsonResponse(new WalletResponse($wallet), Response::HTTP_CREATED);
    }

    #[Route('/transfer', methods: ['POST'])]
    public function transfer(
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        TransferRequest $payload,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $transaction = $this->transferService->transfer(
            $user->getIdNotNull(),
            $payload->fromWalletId(),
            $payload->toWalletId(),
            $payload->amount(),
        );

        return new JsonResponse(new TransactionResponse($transaction), Response::HTTP_CREATED);
    }

    // Up to 18 digits always fits in an int; longer ids cannot exist and get 404 instead of a type error.
    #[Route('/{id}/deposit', requirements: ['id' => '\d{1,18}'], methods: ['POST'])]
    public function deposit(
        int $id,
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        DepositRequest $payload,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $wallet = $this->depositService->deposit($user->getIdNotNull(), $id, $payload->amount());

        return new JsonResponse(new WalletResponse($wallet));
    }
}
