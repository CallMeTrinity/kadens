<?php

namespace App\Security\Voter;

use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Service\CoachingResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Contrôle d'accès aux séances planifiées (instances datées). Contrairement à
 * Exercise/Workout/PlanTemplate, il n'y a **pas de bibliothèque globale** ici :
 * une séance planifiée appartient toujours à un utilisateur. La règle est donc
 * simple : voir/éditer/supprimer réservé au propriétaire, plus son coach accepté.
 *
 * **Deux natures d'écriture, et une seule est fermée au coach.** Depuis que la
 * séance datée porte le réalisé (`LoggedExercise` / `LoggedSet`, cf.
 * `docs/feature-live-tracking.md`), « écrire dessus » recouvre deux gestes qui
 * n'appartiennent pas à la même personne :
 *
 * - EDIT = **programmer**. Déplacer une date, marquer prévu/fait/manqué, noter un
 *   écart léger, retirer la séance du calendrier. Le coach le fait légitimement,
 *   c'est son travail : il reste donc ouvert au coach accepté.
 * - LOG = **consigner ce qui a été fait**. Créer, modifier ou supprimer le
 *   réalisé série par série. Réservé au **seul propriétaire**, jamais au coach :
 *   le coach lit le réalisé de son athlète (VIEW le lui accorde déjà), il ne
 *   l'écrit pas. Personne ne déclare à la place de quelqu'un d'autre ce qu'il a
 *   soulevé.
 *
 * Conséquence à ne pas casser : tout point d'écriture du réalisé, web comme API,
 * teste LOG. Tester EDIT y suffirait syntaxiquement et donnerait la main au
 * coach — c'est exactement la confusion que cet attribut existe pour empêcher.
 */
final class ScheduledWorkoutVoter extends Voter
{
    public const VIEW = 'SCHEDULED_WORKOUT_VIEW';
    public const EDIT = 'SCHEDULED_WORKOUT_EDIT';
    public const DELETE = 'SCHEDULED_WORKOUT_DELETE';
    public const LOG = 'SCHEDULED_WORKOUT_LOG';

    public function __construct(private readonly CoachingResolver $coachingResolver)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::LOG], true)
            && $subject instanceof ScheduledWorkout;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var ScheduledWorkout $subject */
        $owner = $subject->getOwner();

        if ($owner === $user) {
            return true;
        }

        // Le propriétaire est le seul à écrire son réalisé : la branche coach
        // s'arrête ici, sans même interroger la relation.
        if (self::LOG === $attribute) {
            return false;
        }

        return $owner instanceof User && $this->coachingResolver->isAcceptedCoachOf($user, $owner);
    }
}
