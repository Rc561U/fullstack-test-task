<?php

namespace App\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class IdempotencyKeyResolver implements ValueResolverInterface
{
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (IdempotencyKey::class !== $argument->getType()) {
            return [];
        }

        $key = trim($request->headers->get('Idempotency-Key', ''));

        if ('' === $key) {
            throw new BadRequestHttpException('Idempotency-Key header is required.');
        }

        return [new IdempotencyKey($key)];
    }
}
