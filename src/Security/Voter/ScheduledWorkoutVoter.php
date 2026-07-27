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
 * Conséquence assumée en v1 : le coach peut aussi basculer prévu/fait/manqué. On
 * accepte (il aide à la programmation) ; restreindre le « réalisé » à l'athlète
 * demanderait de séparer EDIT (dates) de STATUS (réalisé).
 */
final class ScheduledWorkoutVoter extends Voter
{
    public const VIEW = 'SCHEDULED_WORKOUT_VIEW';
    public const EDIT = 'SCHEDULED_WORKOUT_EDIT';
    public const DELETE = 'SCHEDULED_WORKOUT_DELETE';

    public function __construct(private readonly CoachingResolver $coachingResolver)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
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

        return $owner instanceof User && $this->coachingResolver->isAcceptedCoachOf($user, $owner);
    }
}
