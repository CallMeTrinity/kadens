<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\Sex;
use App\Repository\ExerciseRepository;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;

/**
 * Agrège les stats générales de la page profil. Compose l'existant sans
 * réimplémenter la moindre mise à plat/volume :
 * - compteurs de bibliothèque (comme l'ancienne page d'accueil) ;
 * - observance du mois et « tous temps » (ScheduledWorkoutRepository) ;
 * - répartition par activité + volume réalisé sur l'historique des séances FAITES
 *   (WorkoutMetrics::volume, formaté par UnitFormatter).
 *
 * Le volume itère les séances DONE (fetch-jointes) : c'est le seul point un peu
 * coûteux, assumé pour le mode « complet » choisi.
 */
final class ProfileStats
{
    public function __construct(
        private readonly ScheduledWorkoutRepository $scheduled,
        private readonly WorkoutRepository $workouts,
        private readonly PlanTemplateRepository $plans,
        private readonly ExerciseRepository $exercises,
        private readonly WorkoutMetrics $metrics,
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

        return [
            'counts' => [
                'workouts' => $this->workouts->count(['owner' => $user, 'planLocal' => false]),
                'plans' => $this->plans->count(['owner' => $user]),
                'exercises' => \count($this->exercises->findLibraryForUser($user)),
            ],
            'month' => $this->buildStats($this->scheduled->countByStatusForOwnerBetween($user, $monthStart, $monthEnd)),
            'allTime' => $this->buildStats($this->scheduled->countByStatusForOwner($user)),
            'activityCounts' => $this->activityCounts($user),
            'volume' => $this->lifetimeVolume($user),
            'dots' => $dots,
            'athlete' => $this->athleteCard($user, $dots),
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
     * Répartition des séances FAITES par activité (une séance multi-activités
     * compte dans chacune), triée par fréquence décroissante.
     *
     * @return list<array{activity: ActivityType, sessions: int}>
     */
    private function activityCounts(User $user): array
    {
        $counts = [];
        foreach ($this->scheduled->findDoneWithContentForOwner($user) as $sw) {
            foreach ($this->metrics->distinctActivities($sw->getWorkout()) as $activity) {
                $counts[$activity->value] = ($counts[$activity->value] ?? 0) + 1;
            }
        }

        arsort($counts);

        $out = [];
        foreach ($counts as $value => $sessions) {
            $out[] = ['activity' => ActivityType::from((string) $value), 'sessions' => $sessions];
        }

        return $out;
    }

    /**
     * Volume réalisé cumulé sur l'historique des séances FAITES : tonnage et
     * séries en salle, distance/durée par activité d'endurance. Formaté via
     * UnitFormatter (source unique kg / km / mm:ss).
     *
     * @return array<string, mixed>
     */
    private function lifetimeVolume(User $user): array
    {
        $tonnage = 0.0;
        $gymSets = 0;
        $endurance = [
            'running' => ['meters' => 0, 'seconds' => 0],
            'cycling' => ['meters' => 0, 'seconds' => 0],
            'swimming' => ['meters' => 0, 'seconds' => 0],
        ];

        foreach ($this->scheduled->findDoneWithContentForOwner($user) as $sw) {
            $v = $this->metrics->volume($sw->getWorkout());
            $tonnage += $v['gym']['tonnageKg'];
            $gymSets += $v['gym']['totalSets'];
            foreach ($endurance as $key => $_) {
                $endurance[$key]['meters'] += $v[$key]['meters'];
                $endurance[$key]['seconds'] += $v[$key]['seconds'];
            }
        }

        $format = fn (array $e): array => [
            'meters' => $e['meters'],
            'seconds' => $e['seconds'],
            'distanceLabel' => $this->units->distance($e['meters']),
            'durationLabel' => $this->units->duration($e['seconds']),
        ];

        return [
            'tonnageKg' => $tonnage,
            'tonnageLabel' => $this->units->weight(round($tonnage)),
            'gymSets' => $gymSets,
            'running' => $format($endurance['running']),
            'cycling' => $format($endurance['cycling']),
            'swimming' => $format($endurance['swimming']),
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
