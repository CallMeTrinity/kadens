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
 * les compteurs de bibliothèque, la fiche athlète (mesures saisies) et le score
 * DOTS. Les records de force ne sont pas non plus de son ressort — ils croisent
 * le déclaré et le réalisé, et c'est `AthleteRecords` qui les réconcilie ; ce
 * service ne fait que poser le bloc rendu à sa place dans la fiche.
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
        private readonly AthleteRecords $records,
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

        // Les records d'abord : le total SBD effectif en sort, et le score DOTS
        // s'en déduit. L'ordre n'est pas cosmétique — le calculer sur les
        // seules valeurs saisies contredirait les lignes juste au-dessus.
        $strength = $this->records->strengthFor($user);
        $dots = $this->dots($user, $strength['sbdTotalKg']);
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
            'athlete' => $this->athleteCard($user, $strength, $dots),
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
     * Fiche athlète prête à l'affichage : lignes groupées, valeurs déjà
     * formatées via UnitFormatter (kg, mm:ss). value = null -> « — » côté vue.
     *
     * **Toutes les lignes ont les mêmes clés**, y compris celles qui n'ont rien
     * à y mettre : le fragment Twig les lit sans garde, et une ligne à la forme
     * variable finirait par en demander une à chaque ajout.
     *
     * Le bloc « Force » n'est pas construit ici : ses lignes croisent le déclaré
     * et le réalisé, et cette réconciliation appartient à `AthleteRecords`.
     *
     * @param array{rows: list<array{label: string, value: string|null, note: string|null, exerciseId: int|null, derived: bool}>, sbdTotalKg: float|null} $strength
     *
     * @return array{identity: list<array{label: string, value: string|null, note: string|null, exerciseId: int|null, derived: bool}>, strength: list<array{label: string, value: string|null, note: string|null, exerciseId: int|null, derived: bool}>, endurance: list<array{label: string, value: string|null, note: string|null, exerciseId: int|null, derived: bool}>, bio: ?string, hasAny: bool}
     */
    private function athleteCard(User $user, array $strength, ?float $dots): array
    {
        $kg = fn (?float $v): ?string => null === $v ? null : $this->units->weight($v);
        $time = fn (?int $v): ?string => null === $v ? null : $this->units->duration($v);
        $bmi = $user->getBmi();

        $identity = [
            $this->line('Âge', null !== $user->getAge() ? $user->getAge().' ans' : null),
            $this->line('Sexe', $user->getSex()?->getLabel()),
            $this->line('Taille', null !== $user->getHeightCm() ? $user->getHeightCm().' cm' : null),
            $this->line('Poids', $kg($user->getWeightKg())),
            $this->line('IMC', null !== $bmi ? str_replace('.', ',', (string) $bmi) : null, derived: true),
            $this->line("Années d'entraînement", null !== $user->getTrainingYears() ? $user->getTrainingYears().' ans' : null),
            $this->line('Objectif', $user->getMainGoal()?->getLabel()),
        ];

        // Le score ferme le bloc force : il résume les lignes du dessus, et il
        // se lit sur le total effectif, pas sur les seules valeurs saisies.
        $strengthRows = [
            ...$strength['rows'],
            $this->line('Score DOTS', null !== $dots ? str_replace('.', ',', (string) $dots) : null, derived: true),
        ];

        $endurance = [
            $this->line('5 km', $time($user->getRun5kSeconds())),
            $this->line('10 km', $time($user->getRun10kSeconds())),
            $this->line('Semi-marathon', $time($user->getHalfMarathonSeconds())),
            $this->line('Marathon', $time($user->getMarathonSeconds())),
            $this->line('FTP vélo', null !== $user->getCyclingFtpWatts() ? $user->getCyclingFtpWatts().' W' : null),
            $this->line('100 m natation', $time($user->getSwim100mSeconds())),
        ];

        $hasAny = false;
        foreach ([...$identity, ...$strengthRows, ...$endurance] as $row) {
            if (null !== $row['value']) {
                $hasAny = true;
                break;
            }
        }

        return [
            'identity' => $identity,
            'strength' => $strengthRows,
            'endurance' => $endurance,
            'bio' => $user->getBio(),
            'hasAny' => $hasAny || null !== $user->getBio(),
        ];
    }

    /**
     * Une ligne de fiche, à la même forme que celles d'`AthleteRecords` : ni
     * note ni exercice, parce qu'une mesure saisie ne vient de nulle part
     * d'autre que du formulaire.
     *
     * @return array{label: string, value: string|null, note: string|null, exerciseId: int|null, derived: bool}
     */
    private function line(string $label, ?string $value, bool $derived = false): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'note' => null,
            'exerciseId' => null,
            'derived' => $derived,
        ];
    }

    /**
     * Score de force normalisé DOTS (comparable entre poids de corps), à partir du
     * total SBD, du poids de corps et du sexe. Retourne null si une donnée manque
     * ou si le sexe n'a pas de coefficients (OTHER).
     *
     * Le total est **passé**, pas relu : c'est le total effectif calculé par
     * `AthleteRecords` (déclaré ou battu en séance). Le recalculer ici ferait
     * du score le seul chiffre de la fiche à ignorer un record fraîchement
     * battu.
     */
    private function dots(User $user, ?float $total): ?float
    {
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
