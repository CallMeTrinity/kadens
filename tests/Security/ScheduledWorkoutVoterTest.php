<?php

namespace App\Tests\Security;

use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Repository\CoachingRepository;
use App\Security\Voter\ScheduledWorkoutVoter;
use App\Service\CoachingResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * La garde d'écriture du réalisé (KL-06). Ce que ces tests tiennent, c'est la
 * distinction entre les deux natures d'écriture d'une séance datée : le coach
 * **programme** (EDIT), seul l'athlète **consigne** ce qu'il a fait (LOG).
 *
 * Test unitaire : la relation de coaching est le seul fait qui vienne de la base,
 * et `CoachingResolver` l'isole déjà derrière une méthode. Le double porte donc
 * sur le repository, le résolveur reste le vrai (c'est lui qui refuse un
 * utilisateur non persisté).
 */
final class ScheduledWorkoutVoterTest extends TestCase
{
    private const ATTRIBUTES = [
        ScheduledWorkoutVoter::VIEW,
        ScheduledWorkoutVoter::EDIT,
        ScheduledWorkoutVoter::DELETE,
        ScheduledWorkoutVoter::LOG,
    ];

    public function testOwnerIsGrantedEverythingIncludingLog(): void
    {
        $owner = $this->user(1, 'athlete@example.com');
        $scheduled = $this->scheduled($owner);
        $voter = $this->voter(coachAccepted: false);

        foreach (self::ATTRIBUTES as $attribute) {
            self::assertSame(
                VoterInterface::ACCESS_GRANTED,
                $voter->vote($this->token($owner), $scheduled, [$attribute]),
                sprintf('Le propriétaire doit avoir %s.', $attribute),
            );
        }
    }

    /**
     * Le cœur du ticket : le coach accepté garde VIEW, EDIT et DELETE — déplacer
     * une date ou marquer une séance faite fait partie de son travail — mais
     * n'écrit jamais le réalisé de son athlète.
     */
    public function testAcceptedCoachHasViewEditAndDeleteButNeverLog(): void
    {
        $athlete = $this->user(1, 'athlete@example.com');
        $coach = $this->user(2, 'coach@example.com');
        $scheduled = $this->scheduled($athlete);
        $voter = $this->voter(coachAccepted: true);

        foreach ([ScheduledWorkoutVoter::VIEW, ScheduledWorkoutVoter::EDIT, ScheduledWorkoutVoter::DELETE] as $attribute) {
            self::assertSame(
                VoterInterface::ACCESS_GRANTED,
                $voter->vote($this->token($coach), $scheduled, [$attribute]),
                sprintf('Le coach accepté doit garder %s.', $attribute),
            );
        }

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token($coach), $scheduled, [ScheduledWorkoutVoter::LOG]),
            'Le coach lit le réalisé de son athlète, il ne l\'écrit pas.',
        );
    }

    /**
     * LOG ne consulte même pas la relation de coaching : il n'y a pas de cas où
     * la réponse dépende d'elle. Sans cette garde, un futur « et si le coach
     * était aussi… » se glisserait dans la branche partagée.
     */
    public function testLogNeverAsksWhetherTheUserIsACoach(): void
    {
        $athlete = $this->user(1, 'athlete@example.com');
        $coach = $this->user(2, 'coach@example.com');

        $repository = $this->createMock(CoachingRepository::class);
        $repository->expects(self::never())->method('isAcceptedCoachOf');

        $voter = new ScheduledWorkoutVoter(new CoachingResolver($repository));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token($coach), $this->scheduled($athlete), [ScheduledWorkoutVoter::LOG]),
        );
    }

    public function testStrangerIsDeniedEverything(): void
    {
        $athlete = $this->user(1, 'athlete@example.com');
        $stranger = $this->user(3, 'stranger@example.com');
        $scheduled = $this->scheduled($athlete);
        $voter = $this->voter(coachAccepted: false);

        foreach (self::ATTRIBUTES as $attribute) {
            self::assertSame(
                VoterInterface::ACCESS_DENIED,
                $voter->vote($this->token($stranger), $scheduled, [$attribute]),
                sprintf('Un tiers ne doit pas avoir %s.', $attribute),
            );
        }
    }

    public function testAnonymousIsDeniedEverything(): void
    {
        $scheduled = $this->scheduled($this->user(1, 'athlete@example.com'));
        $voter = $this->voter(coachAccepted: true);

        foreach (self::ATTRIBUTES as $attribute) {
            self::assertSame(
                VoterInterface::ACCESS_DENIED,
                $voter->vote(new NullToken(), $scheduled, [$attribute]),
                sprintf('Un visiteur anonyme ne doit pas avoir %s.', $attribute),
            );
        }
    }

    /** LOG est bien pris en charge par ce voter, pas laissé en abstention. */
    public function testLogIsSupportedAndDoesNotAbstain(): void
    {
        $owner = $this->user(1, 'athlete@example.com');
        $voter = $this->voter(coachAccepted: false);

        self::assertNotSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($this->token($owner), $this->scheduled($owner), [ScheduledWorkoutVoter::LOG]),
        );
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($this->token($owner), new \stdClass(), [ScheduledWorkoutVoter::LOG]),
        );
    }

    // ---- Fixtures ----------------------------------------------------------

    private function voter(bool $coachAccepted): ScheduledWorkoutVoter
    {
        $repository = $this->createStub(CoachingRepository::class);
        $repository->method('isAcceptedCoachOf')->willReturn($coachAccepted);

        return new ScheduledWorkoutVoter(new CoachingResolver($repository));
    }

    private function user(int $id, string $email): User
    {
        $user = (new User())->setEmail($email);
        // CoachingResolver refuse une entité non persistée (pas de clé de cache
        // fiable) : sans id, la branche coach ne serait jamais atteinte.
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function scheduled(User $owner): ScheduledWorkout
    {
        return (new ScheduledWorkout())
            ->setOwner($owner)
            ->setScheduledDate(new \DateTimeImmutable('2026-07-30'));
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
