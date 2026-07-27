<?php

namespace App\Security\Voter;

use App\Entity\Exercise;
use App\Entity\User;
use App\Service\CoachingResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Contrôle d'accès aux exercices.
 *
 * - Exercice AVEC owner : seul le propriétaire peut le modifier ou le supprimer.
 * - Exercice SANS owner (owner null) : bibliothèque globale de l'app, visible
 *   par tout le monde en lecture, éditable/supprimable uniquement par un
 *   ROLE_ADMIN (qui l'alimente aussi depuis `/exercise/new`, en plus de l'import
 *   console).
 * - **VIEW traverse la relation de coaching, dans les deux sens.** Un coach pose
 *   ses propres exercices dans la séance de son athlète, et réciproquement utilise
 *   ceux que l'athlète s'est créés : chacun doit pouvoir ouvrir la fiche de ce
 *   qu'il lit dans une séance qui le concerne. C'est la seule règle symétrique du
 *   projet — EDIT et DELETE restent au propriétaire, et rien de tout cela ne fait
 *   entrer l'exercice dans la bibliothèque de l'autre (`/exercise` reste scopé sur
 *   soi + la globale).
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

    public function __construct(
        private readonly Security $security,
        private readonly CoachingResolver $coachingResolver,
    ) {
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

        $owner = $subject->getOwner();

        if ($owner === $user) {
            return true;
        }

        // Exercice perso d'un tiers : lecture seule, et seulement si une relation
        // de coaching acceptée les lie (dans un sens ou dans l'autre, cf. en-tête).
        return self::VIEW === $attribute
            && ($this->coachingResolver->isAcceptedCoachOf($user, $owner)
                || $this->coachingResolver->isAcceptedCoachOf($owner, $user));
    }
}
