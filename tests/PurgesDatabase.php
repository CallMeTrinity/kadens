<?php

namespace App\Tests;

use App\Entity\ApiToken;
use App\Entity\Coaching;
use App\Entity\DeletedEntity;
use App\Entity\Exercise;
use App\Entity\Goal;
use App\Entity\PairingCode;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le ménage d'un test qui repart d'une base vide.
 *
 * Pourquoi un endroit unique : purger les `User` suppose d'avoir purgé d'abord
 * tout ce qui les référence, et trois de ces liens sont en **RESTRICT** en base
 * (`workout`, `exercise`, `plan_template` — les autres partent en CASCADE). Un
 * `setUp` qui ne supprime que les entités du ticket qu'il teste marche tant que
 * la base est vierge, puis échoue sur la clé étrangère dès qu'un fichier lancé
 * seul, ou un run interrompu, a laissé une séance derrière lui. Ce n'est pas
 * l'ordre alphabétique des tests qui doit tenir l'isolation.
 *
 * L'ordre de la liste est celui des dépendances, et il se lit à l'envers d'un
 * schéma : `ScheduledWorkout` avant `PlanTemplate` (il en cite la trame et la
 * case), `PlanTemplate` avant `Goal` (la table de jointure les lie), tout avant
 * `User`.
 */
trait PurgesDatabase
{
    /**
     * @param EntityManagerInterface $em à re-résoudre après une requête HTTP :
     *                                   celle-ci redémarre le noyau, et le
     *                                   gestionnaire du `setUp` appartient alors
     *                                   à un conteneur éteint
     */
    private function purgeDatabase(EntityManagerInterface $em): void
    {
        $classes = [
            ApiToken::class,
            PairingCode::class,
            Coaching::class,
            ScheduledWorkout::class,
            PlanTemplate::class,
            Goal::class,
            Workout::class,
            Exercise::class,
            User::class,
        ];

        foreach ($classes as $class) {
            foreach ($em->getRepository($class)->findAll() as $entity) {
                $em->remove($entity);
            }
        }
        $em->flush();

        // EN DERNIER : le ménage ci-dessus passe par `$em->remove()`, donc il
        // vient lui-même d'écrire des pierres tombales (TombstoneListener). Les
        // purger avant les suppressions ne servirait à rien.
        foreach ($em->getRepository(DeletedEntity::class)->findAll() as $tombstone) {
            $em->remove($tombstone);
        }
        $em->flush();
    }
}
