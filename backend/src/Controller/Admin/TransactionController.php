<?php

namespace App\Controller\Admin;

use App\Dto\RefundRequest;
use App\Exception\RefundNotAllowedException;
use App\Exception\TransactionNotFoundException;
use App\Repository\TransactionRepository;
use App\Resolver\IdempotencyKey;
use App\Service\RefundService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

class TransactionController extends AbstractController
{
    #[Route('/api/admin/transactions', name: 'admin_transactions_list', methods: ['GET'])]
    public function list(TransactionRepository $transactions): JsonResponse
    {
        $rows = [];
        foreach ($transactions->findForListing() as $tx) {
            $feeDisplayed = bcmul($tx->getAmount(), $tx->getFeeRate(), 2);

            $rows[] = [
                'id' => $tx->getId(),
                'merchantId' => $tx->getMerchant()->getId(),
                'merchantName' => $tx->getMerchant()->getName(),
                'amount' => $tx->getAmount(),
                'currency' => $tx->getCurrency(),
                'feeDisplayed' => $feeDisplayed,
                'status' => $tx->getStatus(),
                'createdAt' => $tx->getCreatedAt()->format(\DATE_ATOM),
                'refundedAmount' => $tx->getRefundedAmount(),
            ];
        }

        return new JsonResponse($rows);
    }

    #[Route('/api/admin/transactions/{id}/refund', name: 'admin_transactions_refund', methods: ['POST'])]
    public function refund(
        int $id,
        #[MapRequestPayload] RefundRequest $payload,
        IdempotencyKey $idempotencyKey,
        RefundService $refundService,
    ): JsonResponse {
        try {
            $result = $refundService->refund($id, $payload, $idempotencyKey);
        } catch (TransactionNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (RefundNotAllowedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(
            [
                'refundId' => $result->refund->getId(),
                'status' => $result->refund->getStatus(),
            ],
            $result->created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }
}
