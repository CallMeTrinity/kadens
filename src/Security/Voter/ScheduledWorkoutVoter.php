<?php

namespace App\Security\Voter;

use App\Entity\ScheduledWorkout;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Contrôle d'accès aux séances planifiées (instances datées). Contrairement à
 * Exercise/Workout/PlanTemplate, il n'y a **pas de bibliothèque globale** ici :
 * une séance planifiée appartient toujours à un utilisateur. La règle est donc
 * simple : voir/éditer/supprimer réservé au propriétaire.
 */
final class ScheduledWorkoutVoter extends Voter
{
    public const VIEW = 'SCHEDULED_WORKOUT_VIEW';
    public const EDIT = 'SCHEDULED_WORKOUT_EDIT';
    public const DELETE = 'SCHEDULED_WORKOUT_DELETE';

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
        return $subject->getOwner() === $user;
    }
}
