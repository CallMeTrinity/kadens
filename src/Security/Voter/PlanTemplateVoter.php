<?php

namespace App\Security\Voter;

use App\Entity\PlanTemplate;
use App\Entity\User;
use App\Service\CoachingResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Contrôle d'accès aux plans (templates multi-semaines). Même logique que
 * WorkoutVoter et ExerciseVoter :
 *
 * - Plan AVEC owner : voir/éditer/supprimer réservé au propriétaire.
 * - Plan SANS owner (owner null) : future bibliothèque globale de plans,
 *   lecture ouverte à tous, édition/suppression réservées à un ROLE_ADMIN.
 * - Plan d'un athlète coaché : son coach **accepté** est co-éditeur, et peut
 *   donc bâtir un plan directement dans l'éditeur de plan habituel.
 *
 * Un éventuel partage public en lecture (slug) passera par une route dédiée,
 * pas par ce voter.
 */
final class PlanTemplateVoter extends Voter
{
    public const VIEW = 'PLAN_TEMPLATE_VIEW';
    public const EDIT = 'PLAN_TEMPLATE_EDIT';
    public const DELETE = 'PLAN_TEMPLATE_DELETE';

    public function __construct(
        private readonly Security $security,
        private readonly CoachingResolver $coachingResolver,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof PlanTemplate;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var PlanTemplate $subject */
        $owner = $subject->getOwner();

        if (null === $owner) {
            return self::VIEW === $attribute || $this->security->isGranted('ROLE_ADMIN');
        }

        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($owner === $user) {
            return true;
        }

        return $this->coachingResolver->isAcceptedCoachOf($user, $owner);
    }
}
