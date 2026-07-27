<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\CoachingRepository;

/**
 * Portée des index de bibliothèque (séances, plans) quand l'utilisateur est coach.
 *
 * Le contenu créé pour un athlète lui appartient (`setOwner($athlete)`, cf.
 * CoachController) : il sortait donc des index du coach, qui se scopaient sur
 * `owner = utilisateur courant`. Un coach ne retrouvait son travail que sur la
 * fiche `/coach/athlete/{id}`, une page à la fois.
 *
 * Ce service élargit la portée à **soi + ses athlètes suivis** (relations
 * acceptées, un seul sens : les contenus de mes coachs ne me regardent pas). La
 * séparation reste visible à l'écran : une facette par athlète, « Moi » actif par
 * défaut, et un badge de propriétaire sur les cartes qui ne sont pas les siennes.
 *
 * Pas de champ « créé par » : le coach a de toute façon le droit de voir et
 * d'éditer tout le contenu de son athlète (branche coach des voters), et c'est
 * déjà ce que montre la fiche athlète. Distinguer l'auteur demanderait une
 * colonne supplémentaire sans reprise possible de l'historique.
 */
final class CoachedLibrary
{
    public function __construct(private readonly CoachingRepository $coachingRepository)
    {
    }

    /**
     * Athlètes dont `$user` est le coach accepté, triés par identifiant pour que
     * l'ordre des facettes soit stable d'une page à l'autre.
     *
     * @return list<User>
     */
    public function athletesOf(User $user): array
    {
        $athletes = [];
        foreach ($this->coachingRepository->findAcceptedAthletes($user) as $coaching) {
            $athlete = $coaching->getAthlete();
            if (null !== $athlete) {
                $athletes[] = $athlete;
            }
        }

        usort($athletes, static fn (User $a, User $b) => strcasecmp($a->getUserIdentifier(), $b->getUserIdentifier()));

        return $athletes;
    }

    /**
     * Propriétaires à charger dans un index : soi d'abord, puis ses athlètes.
     * Passé tel quel aux repositories (`owner IN (...)`).
     *
     * @return list<User>
     */
    public function ownersFor(User $user): array
    {
        return [$user, ...$this->athletesOf($user)];
    }

    /**
     * Puces du groupe de facette « owner », dans l'ordre soi puis athlètes. La
     * valeur d'une puce est l'**id** du propriétaire : c'est aussi ce que les
     * cartes portent en `data-facet-owner`.
     *
     * Renvoie une liste vide s'il n'y a aucun athlète : sans coaching, le groupe
     * disparaît de la barre de filtres (`_filterbar` saute les groupes vides) et
     * la page reste exactement celle d'avant.
     *
     * @param list<User>     $athletes         issus de athletesOf(), pour ne pas re-requêter
     * @param array<int,int> $countsByOwnerId  effectif de l'index par id de propriétaire
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    public function ownerFacets(User $user, array $athletes, array $countsByOwnerId): array
    {
        if ([] === $athletes) {
            return [];
        }

        $facets = [[
            'value' => (string) $user->getId(),
            'label' => 'Moi',
            'count' => $countsByOwnerId[$user->getId()] ?? 0,
        ]];

        foreach ($athletes as $athlete) {
            $facets[] = [
                'value' => (string) $athlete->getId(),
                'label' => $athlete->getUserIdentifier(),
                'count' => $countsByOwnerId[$athlete->getId()] ?? 0,
            ];
        }

        return $facets;
    }
}
