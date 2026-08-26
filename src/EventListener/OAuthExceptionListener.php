<?php

namespace App\EventListener;

use App\Service\OAuth\AuthorizationServer\OAuthException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rend les erreurs du serveur d'autorisation au format OAuth.
 *
 * Ici plutôt que dans chaque contrôleur : les validations vivent dans les
 * services, et sans ce listener chaque endpoint devrait les entourer d'un
 * try/catch pour reformater la même chose.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
class OAuthExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof OAuthException) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => $exception->error, 'error_description' => $exception->description],
            $exception->status,
            // RFC 6749 §5.2 : la réponse d'erreur ne doit pas être mise en cache.
            ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'],
        ));
    }
}
