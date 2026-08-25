<?php

namespace App\EventListener;

use App\Service\OAuth\ProtectedResourceMetadata;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Complète le `WWW-Authenticate` de nos 401 avec le pointeur `resource_metadata`
 * (RFC 9728 §5.1). C'est le seul fil qu'un client MCP ait pour découvrir où
 * s'authentifier : il tape l'endpoint sans jeton, lit l'en-tête du 401, et va
 * chercher le document de métadonnées à l'URL qu'il y trouve.
 *
 * Le crochet est la réponse plutôt qu'un événement Lexik, parce que le 401 sort
 * de deux endroits — l'entry point du firewall quand il n'y a pas de jeton, le
 * failure handler quand il y en a un mauvais — et que les deux passent ici.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
class WwwAuthenticateListener
{
    public function __construct(
        private readonly ProtectedResourceMetadata $metadata,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();

        if (Response::HTTP_UNAUTHORIZED !== $response->getStatusCode()) {
            return;
        }

        $header = $response->headers->get('WWW-Authenticate');

        // Le http_basic du profiler rend un `Basic realm=…` : il ne nous regarde pas.
        if (null === $header || !str_starts_with($header, 'Bearer') || str_contains($header, 'resource_metadata=')) {
            return;
        }

        // Lexik n'émet que « Bearer », sans paramètre : le séparateur est alors
        // l'espace, et la virgule seulement s'il y en avait déjà un.
        $pointer = sprintf('resource_metadata="%s"', $this->metadata->url());

        $response->headers->set(
            'WWW-Authenticate',
            'Bearer' === trim($header) ? 'Bearer '.$pointer : $header.', '.$pointer,
        );
    }
}
