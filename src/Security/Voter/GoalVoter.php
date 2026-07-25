<?php

namespace App\Security\Voter;

use App\Entity\Goal;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Contrôle d'accès aux objectifs. Comme les séances planifiées, un objectif
 * appartient toujours à un utilisateur (pas de bibliothèque globale) : voir /
 * éditer / supprimer réservé au propriétaire.
 */
final class GoalVoter extends Voter
{
    public const VIEW = 'GOAL_VIEW';
    public const EDIT = 'GOAL_EDIT';
    public const DELETE = 'GOAL_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof Goal;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Goal $subject */
        return $subject->getOwner() === $user;
    }
}
