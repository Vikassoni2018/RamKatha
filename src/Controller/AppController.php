<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Small shared helpers for controllers (kept thin: business logic lives in services). */
abstract class AppController extends AbstractController
{
    protected function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    protected function requireUser(): User
    {
        return $this->currentUser() ?? throw new AccessDeniedException('Login required.');
    }

    protected function page(Request $request): int
    {
        return max(1, min(10000, $request->query->getInt('page', 1)));
    }

    /** CSRF check for JSON endpoints: header X-CSRF-Token or "_token" in the JSON body (beacons). */
    protected function isApiCsrfValid(Request $request, array $payload = []): bool
    {
        $token = $request->headers->get('X-CSRF-Token') ?? ($payload['_token'] ?? null);

        return \is_string($token) && $this->isCsrfTokenValid('api', $token);
    }

    /** CSRF check for classic POST action forms (buttons rendered with _csrf_token). */
    protected function assertFormCsrf(Request $request, string $id = 'submit'): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_csrf_token'))) {
            throw new BadRequestHttpException('Invalid CSRF token. Please reload the page and try again.');
        }
    }

    /** @return array<string, mixed> */
    protected function jsonPayload(Request $request): array
    {
        if ('' === $request->getContent()) {
            return [];
        }
        try {
            $data = json_decode($request->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Malformed JSON.');
        }

        return \is_array($data) ? $data : [];
    }

    protected function jsonError(string $message, int $status = 400): JsonResponse
    {
        return $this->json(['message' => $message], $status);
    }
}
