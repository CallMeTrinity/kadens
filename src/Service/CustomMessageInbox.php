<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CustomMessage;
use App\Entity\User;
use App\Repository\CustomMessageRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ce qu'un compte a à lire parmi les messages programmés, et l'adresse de leur
 * image. Seul point d'entrée pour la modale web, le bootstrap mobile et
 * l'aperçu du back-office.
 *
 * ## Pourquoi une URL signée pour l'image
 *
 * Le téléphone ne pose son jeton que sur ses appels JSON : une balise image n'a
 * pas d'en-tête `Authorization`, et ouvrir une exception pour elle ferait
 * sortir le jeton du client API. La signature (`UriSigner`, clé `APP_SECRET`)
 * fait donc office de droit de lecture, avec une expiration qui suit la fin du
 * message. Le nom de fichier est dans l'URL : remplacer l'image change
 * l'adresse, et aucun cache ne ressert l'ancienne.
 */
final class CustomMessageInbox
{
    /** Le téléphone reçoit les messages à venir sur cet horizon, pour les afficher hors réseau le jour venu. */
    public const string MOBILE_HORIZON = 'P14D';

    /** Marge après la fin : un fuseau d'avance, un téléphone resté hors réseau la veille. */
    private const string MEDIA_GRACE = 'P2D';

    public function __construct(
        private readonly CustomMessageRepository $messages,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire(service: 'uri_signer')]
        private readonly UriSigner $signer,
    ) {
    }

    /** Le message à montrer aujourd'hui sur le web, s'il y en a un. Un seul à la fois, le plus ancien d'abord. */
    public function pendingFor(User $user, ?\DateTimeImmutable $today = null): ?CustomMessage
    {
        $today ??= new \DateTimeImmutable('today');

        return $this->messages->findUnseenFor($user, $today, $today)[0] ?? null;
    }

    /**
     * Les messages non vus en cours ou à venir sur l'horizon mobile.
     *
     * @return list<CustomMessage>
     */
    public function upcomingFor(User $user, \DateTimeImmutable $today): array
    {
        return $this->messages->findUnseenFor($user, $today, $today->add(new \DateInterval(self::MOBILE_HORIZON)));
    }

    /**
     * URL absolue et signée de l'image, ou null sans image. Elle reste valable
     * jusqu'à deux jours après la fin du message, et au moins jusqu'à demain :
     * l'aperçu du back-office montre aussi les messages passés.
     */
    public function mediaUrl(CustomMessage $message): ?string
    {
        $file = $message->getMediaFile();
        if (null === $file || null === $message->getId()) {
            return null;
        }

        $expires = max(
            ($message->getEndsOn() ?? new \DateTimeImmutable('today'))->add(new \DateInterval(self::MEDIA_GRACE)),
            new \DateTimeImmutable('tomorrow'),
        );

        return $this->signer->sign(
            $this->urls->generate('app_message_media', ['id' => $message->getId(), 'file' => $file], UrlGeneratorInterface::ABSOLUTE_URL),
            $expires,
        );
    }
}
