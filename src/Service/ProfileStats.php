<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\Sex;
use App\Repository\ExerciseRepository;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;

/**
 * Le RÉSUMÉ de la page profil : ce qu'on voit sans avoir rien demandé, sur la
 * page d'accueil et sur la fiche athlète du coach.
 *
 * Il ne calcule plus rien lui-même côté entraînement — c'est TrainingStats qui
 * porte le moteur, sur une fenêtre de temps, et ce service en prend la fenêtre
 * « depuis le début ». La conséquence recherchée : le résumé du profil et le
 * détail de `/profile/stats` sont **le même agrégat**, ils ne peuvent pas
 * afficher deux tonnages différents.
 *
 * Ce qui lui reste en propre est ce que TrainingStats n'a pas à connaître :
 * les compteurs de bibliothèque, la fiche athlète (mesures saisies, records
 * déclarés) et le score DOTS qui s'en déduit.
 *
 * Rappel de périmètre, hérité de TrainingStats : le tonnage vient du RÉALISÉ
 * (LoggedSet), les distances du PRESCRIT des séances faites — le cardio ne se
 * logue jamais.
 */
final class ProfileStats
{
    public function __construct(
        private readonly ScheduledWorkoutRepository $scheduled,
        private readonly WorkoutRepository $workouts,
        private readonly PlanTemplateRepository $plans,
        private readonly ExerciseRepository $exercises,
        private readonly TrainingStats $training,
        private readonly UnitFormatter $units,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $now = new \DateTimeImmutable();
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $monthEnd = $now->modify('last day of this month')->setTime(23, 59, 59);

        $dots = $this->dots($user);
        $allTime = $this->training->over($user, StatsPeriod::allTime($now));

        return [
            'counts' => [
                'workouts' => $this->workouts->count(['owner' => $user, 'planLocal' => false]),
                'plans' => $this->plans->count(['owner' => $user]),
                'exercises' => \count($this->exercises->findLibraryForUser($user)),
            ],
            'month' => $this->buildStats($this->scheduled->countByStatusForOwnerBetween($user, $monthStart, $monthEnd)),
            'allTime' => $allTime['adherence'],
            'activityCounts' => $allTime['activityCounts'],
            'volume' => $this->summaryVolume($allTime['volume']),
            'dots' => $dots,
            'athlete' => $this->athleteCard($user, $dots),
        ];
    }

    /**
     * Met le volume de TrainingStats à la forme attendue par
     * `profile/_stats.html.twig` (tonnage à plat, endurance par activité).
     *
     * Les clés `tonnageKg` / `gymSets` sont conservées telles quelles : elles
     * datent de la page d'origine et ce fragment est aussi rendu sur la fiche
     * athlète du coach.
     *
     * @param array<string, mixed> $volume
     *
     * @return array<string, mixed>
     */
    private function summaryVolume(array $volume): array
    {
        /** @var array{tonnageKg: float, tonnageLabel: string, workingSets: int} $gym */
        $gym = $volume['gym'];

        return [
            'tonnageKg' => $gym['tonnageKg'],
            'tonnageLabel' => $gym['tonnageLabel'],
            'gymSets' => $gym['workingSets'],
            'running' => $volume['running'],
            'cycling' => $volume['cycling'],
            'swimming' => $volume['swimming'],
        ];
    }

    /**
     * Fiche athlète prête à l'affichage : lignes {label, value} groupées, valeurs
     * déjà formatées via UnitFormatter (kg, mm:ss). value = null -> « — » côté vue.
     *
     * @return array{identity: list<array{label: string, value: ?string, derived?: bool}>, strength: list<array{label: string, value: ?string, derived?: bool}>, endurance: list<array{label: string, value: ?string, derived?: bool}>, bio: ?string, hasAny: bool}
     */
    private function athleteCard(User $user, ?float $dots): array
    {
        $kg = fn (?float $v): ?string => null === $v ? null : $this->units->weight($v);
        $time = fn (?int $v): ?string => null === $v ? null : $this->units->duration($v);
        $bmi = $user->getBmi();
        $total = $user->getSbdTotalKg();

        $identity = [
            ['label' => 'Âge', 'value' => null !== $user->getAge() ? $user->getAge().' ans' : null],
            ['label' => 'Sexe', 'value' => $user->getSex()?->getLabel()],
            ['label' => 'Taille', 'value' => null !== $user->getHeightCm() ? $user->getHeightCm().' cm' : null],
            ['label' => 'Poids', 'value' => $kg($user->getWeightKg())],
            ['label' => 'IMC', 'value' => null !== $bmi ? str_replace('.', ',', (string) $bmi) : null, 'derived' => true],
            ['label' => "Années d'entraînement", 'value' => null !== $user->getTrainingYears() ? $user->getTrainingYears().' ans' : null],
            ['label' => 'Objectif', 'value' => $user->getMainGoal()?->getLabel()],
        ];

        $strength = [
            ['label' => 'Squat', 'value' => $kg($user->getSquat1rmKg())],
            ['label' => 'Développé couché', 'value' => $kg($user->getBench1rmKg())],
            ['label' => 'Soulevé de terre', 'value' => $kg($user->getDeadlift1rmKg())],
            ['label' => 'Total SBD', 'value' => $kg($total), 'derived' => true],
            ['label' => 'Développé militaire', 'value' => $kg($user->getOhp1rmKg())],
            ['label' => 'Traction lestée', 'value' => $kg($user->getWeightedPullupKg())],
            ['label' => 'Score DOTS', 'value' => null !== $dots ? str_replace('.', ',', (string) $dots) : null, 'derived' => true],
        ];

        $endurance = [
            ['label' => '5 km', 'value' => $time($user->getRun5kSeconds())],
            ['label' => '10 km', 'value' => $time($user->getRun10kSeconds())],
            ['label' => 'Semi-marathon', 'value' => $time($user->getHalfMarathonSeconds())],
            ['label' => 'Marathon', 'value' => $time($user->getMarathonSeconds())],
            ['label' => 'FTP vélo', 'value' => null !== $user->getCyclingFtpWatts() ? $user->getCyclingFtpWatts().' W' : null],
            ['label' => '100 m natation', 'value' => $time($user->getSwim100mSeconds())],
        ];

        $hasAny = false;
        foreach ([...$identity, ...$strength, ...$endurance] as $row) {
            if (null !== $row['value']) {
                $hasAny = true;
                break;
            }
        }

        return [
            'identity' => $identity,
            'strength' => $strength,
            'endurance' => $endurance,
            'bio' => $user->getBio(),
            'hasAny' => $hasAny || null !== $user->getBio(),
        ];
    }

    /**
     * Score de force normalisé DOTS (comparable entre poids de corps), à partir du
     * total SBD, du poids de corps et du sexe. Retourne null si une donnée manque
     * ou si le sexe n'a pas de coefficients (OTHER).
     */
    private function dots(User $user): ?float
    {
        $total = $user->getSbdTotalKg();
        $bw = $user->getWeightKg();
        $sex = $user->getSex();
        if (null === $total || null === $bw || $bw <= 0 || null === $sex) {
            return null;
        }

        // Coefficients officiels DOTS (polynôme au dénominateur, bw en kg).
        $coefficients = match ($sex) {
            Sex::MALE => [-307.75076, 24.0900756, -0.1918759221, 0.0007391293, -0.000001093],
            Sex::FEMALE => [-57.96288, 13.6175032, -0.1126655495, 0.0005158568, -0.0000010706],
            Sex::OTHER => null,
        };
        if (null === $coefficients) {
            return null;
        }

        [$a, $b, $c, $d, $e] = $coefficients;
        $denominator = $a + $b * $bw + $c * $bw ** 2 + $d * $bw ** 3 + $e * $bw ** 4;
        if ($denominator <= 0) {
            return null;
        }

        return round($total * 500 / $denominator, 1);
    }

    /**
     * Transforme des compteurs par statut en stats d'observance consommables par
     * `components/_status_stats.html.twig` (adherence = done/(done+missed), float
     * 0..1 ou null si rien d'échu). Même contrat que l'ex-SummaryController.
     *
     * @param array<string, int> $counts
     *
     * @return array{done: int, missed: int, planned: int, total: int, adherence: float|null}
     */
    private function buildStats(array $counts): array
    {
        $done = $counts['done'] ?? 0;
        $missed = $counts['missed'] ?? 0;
        $planned = $counts['planned'] ?? 0;
        $settled = $done + $missed;

        return [
            'done' => $done,
            'missed' => $missed,
            'planned' => $planned,
            'total' => $done + $missed + $planned,
            'adherence' => $settled > 0 ? $done / $settled : null,
        ];
    }
}
