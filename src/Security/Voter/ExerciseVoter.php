<?php

namespace App\Security\Voter;

use App\Entity\Exercise;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Contrôle d'accès aux exercices : seul le propriétaire peut voir/éditer/supprimer.
 *
 * En Phase 6 (page publique en lecture seule), seule la règle VIEW évoluera
 * (lecture publique si l'exercice est publié) ; EDIT et DELETE resteront
 * réservés au propriétaire. La séparation des attributs est là pour ça.
 */
final class ExerciseVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const EDIT = 'EDIT';
    public const DELETE = 'DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof Exercise;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Exercise $subject */
        return $subject->getOwner() === $user;
    }
}
