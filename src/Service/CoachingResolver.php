<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\CoachingRepository;

/**
 * Point d'entrée unique de la question « X est-il coach accepté de Y ? ».
 *
 * Les voters s'exécutent très souvent (une fois par entité affichée : liste de
 * séances, grille de calendrier…). Sans mémoïsation, chaque décision d'accès
 * déclencherait un COUNT. Le cache est porté par l'instance du service, donc de
 * durée de vie requête (les services Symfony ne sont pas partagés entre requêtes) :
 * pas de risque de servir une autorisation périmée après acceptation/fin de
 * relation, qui prend effet à la requête suivante.
 */
final class CoachingResolver
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(private readonly CoachingRepository $coachingRepository)
    {
    }

    public function isAcceptedCoachOf(User $coach, User $athlete): bool
    {
        if ($coach === $athlete) {
            return false;
        }

        $coachId = $coach->getId();
        $athleteId = $athlete->getId();

        // Entité non encore persistée : rien à trouver en base, et pas de clé de
        // cache fiable.
        if (null === $coachId || null === $athleteId) {
            return false;
        }

        $key = $coachId.'-'.$athleteId;

        return $this->cache[$key] ??= $this->coachingRepository->isAcceptedCoachOf($coach, $athlete);
    }

    /**
     * À appeler après une mutation de relation (acceptation, fin) faite dans la
     * même requête, pour que les décisions suivantes voient le nouvel état.
     */
    public function reset(): void
    {
        $this->cache = [];
    }
}
