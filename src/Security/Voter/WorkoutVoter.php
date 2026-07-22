<?php

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\Workout;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Contrôle d'accès aux séances. Même logique que ExerciseVoter :
 *
 * - Séance AVEC owner : voir/éditer/supprimer réservé au propriétaire.
 * - Séance SANS owner (owner null) : future bibliothèque globale de séances,
 *   lecture ouverte à tous, édition/suppression réservées à un ROLE_ADMIN.
 *
 * Le partage public en lecture (Phase 4) passera par une route slug dédiée,
 * pas par ce voter.
 */
final class WorkoutVoter extends Voter
{
    public const VIEW = 'WORKOUT_VIEW';
    public const EDIT = 'WORKOUT_EDIT';
    public const DELETE = 'WORKOUT_DELETE';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof Workout;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var Workout $subject */
        if (null === $subject->getOwner()) {
            return self::VIEW === $attribute || $this->security->isGranted('ROLE_ADMIN');
        }

        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $subject->getOwner() === $user;
    }
}
