<?php

namespace App\Security\Voter;

use App\Entity\Exercise;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Contrôle d'accès aux exercices.
 *
 * - Exercice AVEC owner : seul le propriétaire peut voir/éditer/supprimer.
 * - Exercice SANS owner (owner null) : bibliothèque globale de l'app, visible
 *   par tout le monde en lecture, éditable/supprimable uniquement par un
 *   ROLE_ADMIN (sinon alimentée par l'import console).
 *
 * En Phase 6 (page publique en lecture seule), la règle VIEW évoluera encore
 * (lecture publique si l'exercice est publié). La séparation des attributs est
 * là pour ça.
 */
final class ExerciseVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const EDIT = 'EDIT';
    public const DELETE = 'DELETE';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof Exercise;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var Exercise $subject */

        // Bibliothèque globale (sans owner) : lecture ouverte à tous,
        // édition/suppression réservées aux admins.
        if ($subject->getOwner() === null) {
            return $attribute === self::VIEW || $this->security->isGranted('ROLE_ADMIN');
        }

        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // Exercice perso : voir/éditer/supprimer réservé à son propriétaire.
        return $subject->getOwner() === $user;
    }
}
