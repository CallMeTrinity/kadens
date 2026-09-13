<?php

namespace App\Controller;

use App\Entity\IntervalsConnection;
use App\Entity\User;
use App\Enum\ActivitySource;
use App\Repository\ImportedActivityRepository;
use App\Repository\IntervalsConnectionRepository;
use App\Service\IntervalsAuthException;
use App\Service\IntervalsClient;
use App\Service\IntervalsImporter;
use App\Service\IntervalsUnavailableException;
use App\Service\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le compte Intervals.icu d'un utilisateur : connecter, synchroniser,
 * déconnecter. Trois POST depuis la carte de `/profile/settings`, chacun avec son
 * CSRF, chacun terminé par une redirection et un message.
 *
 * **Réservé au titulaire du compte, sans exception coach.** Toutes les actions
 * portent sur `$this->getUser()` et aucune ne prend d'utilisateur en paramètre :
 * il n'existe pas de chemin pour qu'un coach colle une clé ou synchronise pour
 * son athlète. Il lit le réalisé importé, il ne l'importe pas.
 */
final class IntervalsController extends AbstractController
{
    /** Une clé Intervals fait une trentaine de caractères ; au-delà, c'est un copier-coller raté. */
    private const int API_KEY_MAX = 200;

    public function __construct(
        private readonly IntervalsConnectionRepository $connections,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Valide la clé **avant** de la garder, par un appel réel : une clé mal
     * collée doit échouer ici, sous les yeux de l'utilisateur, pas au premier
     * « Synchroniser ». Recoller une clé remplace la précédente sans toucher aux
     * activités déjà importées.
     */
    #[Route('/profile/intervals/connect', name: 'app_intervals_connect', methods: ['POST'])]
    public function connect(Request $request, IntervalsClient $client, SecretBox $secretBox): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('intervals_connect', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $apiKey = trim($request->getPayload()->getString('apiKey'));

        if ('' === $apiKey || mb_strlen($apiKey) > self::API_KEY_MAX) {
            return $this->back('error', 'Colle la clé d\'API affichée dans Intervals.icu, Settings, Developer Settings.');
        }

        try {
            $athlete = $client->athlete($apiKey);
        } catch (IntervalsAuthException) {
            return $this->back('error', 'Intervals.icu refuse cette clé. Vérifie-la ou génères-en une nouvelle.');
        } catch (IntervalsUnavailableException) {
            return $this->back('error', 'Intervals.icu ne répond pas. Réessaie dans un instant.');
        }

        $sealed = $secretBox->seal($apiKey);
        $connection = $this->connections->findForOwner($user);

        if (null === $connection) {
            $this->entityManager->persist(new IntervalsConnection($user, $sealed, $athlete['name']));
        } else {
            $connection->replaceApiKey($sealed, $athlete['name']);
        }

        $this->entityManager->flush();

        return $this->back('success', 'Compte Intervals.icu connecté. Tu peux synchroniser tes activités.');
    }

    #[Route('/profile/intervals/sync', name: 'app_intervals_sync', methods: ['POST'])]
    public function sync(
        Request $request,
        IntervalsImporter $importer,
        #[Target('intervalsSyncLimiter')] RateLimiterFactoryInterface $limiter,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('intervals_sync', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if (null === $this->connections->findForOwner($user)) {
            return $this->back('error', 'Connecte d\'abord ton compte Intervals.icu.');
        }

        if (!$limiter->create('user-'.$user->getId())->consume()->isAccepted()) {
            return $this->back('error', 'Synchronisation déjà lancée à l\'instant. Réessaie dans une minute.');
        }

        try {
            $report = $importer->sync($user);
        } catch (IntervalsAuthException) {
            return $this->back('error', 'Intervals.icu refuse la clé enregistrée. Colle une nouvelle clé pour reprendre.');
        } catch (IntervalsUnavailableException) {
            return $this->back('error', 'Intervals.icu ne répond pas. Ce qui a pu être importé est conservé, réessaie plus tard.');
        }

        return $this->back('success', $report->summary());
    }

    /**
     * Couper le lien supprime la clé. Les activités importées **restent** par
     * défaut : ce sont des sorties réellement faites, rattachées à des séances et
     * comptées dans les statistiques, pas une donnée de la connexion. Les effacer
     * est un choix explicite (case à cocher), jamais un effet de bord.
     */
    #[Route('/profile/intervals/disconnect', name: 'app_intervals_disconnect', methods: ['POST'])]
    public function disconnect(Request $request, ImportedActivityRepository $activities): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('intervals_disconnect', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $connection = $this->connections->findForOwner($user);

        if (null !== $connection) {
            $this->entityManager->remove($connection);
            $this->entityManager->flush();
        }

        if ($request->getPayload()->getBoolean('purge')) {
            $deleted = $activities->deleteForOwner($user, ActivitySource::INTERVALS);

            return $this->back('success', \sprintf('Compte Intervals.icu déconnecté, %d activité%s supprimée%s.', $deleted, $deleted > 1 ? 's' : '', $deleted > 1 ? 's' : ''));
        }

        return $this->back('success', 'Compte Intervals.icu déconnecté. Les activités déjà importées sont conservées.');
    }

    private function back(string $type, string $message): Response
    {
        $this->addFlash($type, $message);

        return $this->redirect($this->generateUrl('app_profile_settings').'#intervals-panel');
    }
}
