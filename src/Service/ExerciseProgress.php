<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\PlanTemplate;
use App\Entity\User;
use App\Enum\ProgressRange;
use App\Repository\LoggedSetRepository;
use App\Repository\ScheduledWorkoutRepository;

/**
 * Les courbes de progression d'un exercice sur une fenêtre de temps : charge
 * max, 1RM estimé, et pour ce qui ne se charge pas, répétitions et tenue.
 *
 * Complète `ExerciseTrajectory` sans la doubler : la trajectoire dit « où j'en
 * suis » (record, dernière fois, dix dernières séances), ce service dit « par où
 * je suis passé » sur une période choisie. Il lit une **agrégation par séance**
 * (`LoggedSetRepository::sessionPeaksForExercise`), jamais les séries une à une :
 * « tout » ne remonte qu'une ligne par séance.
 *
 * Mêmes règles que le reste des performances, portées par la source : le
 * réalisé lu est celui de l'utilisateur demandé, l'échauffement n'entre dans
 * aucune courbe, une série non chiffrée non plus.
 *
 * La géométrie est calculée ici, en pourcentages, et le template ne fait que la
 * poser — comme `heightPct` pour les barres. Pas de bibliothèque de graphiques :
 * la page est auto-suffisante (cache offline) et la courbe s'imprime.
 *
 * @phpstan-type ChartPoint array{scheduledWorkoutId: int, date: \DateTimeImmutable, value: float, label: string, x: float, y: float, isBest: bool}
 * @phpstan-type ChartTick array{y: float, label: string}
 * @phpstan-type Chart array{key: string, title: string, hint: string, points: list<ChartPoint>, line: string, ticks: list<ChartTick>, first: ChartPoint, last: ChartPoint, best: ChartPoint, direction: string}
 * @phpstan-type Progress array{range: ProgressRange, ranges: list<ProgressRange>, plan: PlanTemplate|null, start: \DateTimeImmutable|null, sessions: int, charts: list<Chart>}
 */
final class ExerciseProgress
{
    /** Marge haute et basse du tracé, en % de la hauteur : un point ne touche jamais le cadre. */
    private const Y_TOP = 10.0;
    private const Y_BOTTOM = 90.0;

    /** Marge gauche et droite, en % de la largeur : le marqueur d'extrémité reste entier. */
    private const X_MARGIN = 2.0;

    public function __construct(
        private readonly LoggedSetRepository $sets,
        private readonly ScheduledWorkoutRepository $scheduled,
        private readonly UnitFormatter $units,
    ) {
    }

    /**
     * Les courbes d'un exercice pour un utilisateur, sur la fenêtre demandée.
     *
     * La valeur brute de `?range=` est lue ici et nulle part ailleurs. Inconnue,
     * absente, ou « plan » sans plan en cours, elle retombe sur la fenêtre par
     * défaut — le plan en cours s'il y en a un, sinon un an — plutôt que de
     * lever : un lien périmé doit afficher des courbes, pas une 404.
     *
     * `charts` peut être vide : une fenêtre où l'exercice a été fait moins de
     * deux fois n'a pas de pente à montrer, et c'est au template de le dire.
     *
     * @return Progress
     */
    public function for(User $user, Exercise $exercise, ?string $rawRange, ?\DateTimeImmutable $now = null): array
    {
        $today = ($now ?? new \DateTimeImmutable())->setTime(0, 0);
        $plan = $this->scheduled->findCurrentPlanRun($user, $today);

        $ranges = null === $plan
            ? [ProgressRange::SIX_MONTHS, ProgressRange::YEAR, ProgressRange::ALL]
            : ProgressRange::cases();

        $range = ProgressRange::tryFrom((string) $rawRange);
        if (null === $range || !\in_array($range, $ranges, true)) {
            $range = null !== $plan ? ProgressRange::PLAN : ProgressRange::YEAR;
        }

        // Le plan se borne aux deux bouts de ses séances datées ; les autres
        // fenêtres n'ont qu'une borne basse — rien n'est réalisé dans le futur.
        [$start, $end] = match ($range) {
            ProgressRange::PLAN => [$plan['start'] ?? null, $plan['end'] ?? null],
            ProgressRange::SIX_MONTHS => [$today->modify('-6 months')->modify('+1 day'), null],
            ProgressRange::YEAR => [$today->modify('-1 year')->modify('+1 day'), null],
            ProgressRange::ALL => [null, null],
        };

        $id = $exercise->getId();
        $peaks = null === $id ? [] : $this->sets->sessionPeaksForExercise($user, $id, $start, $end);

        $charts = array_values(array_filter([
            $this->chart('top', 'Charge max', 'Série de travail la plus lourde de chaque séance.', $peaks, 'topWeight', $this->weight(...)),
            $this->chart('e1rm', '1RM estimé', sprintf('Formule d\'Epley sur la meilleure série de chaque séance, jusqu\'à %d répétitions.', LoggedSetRepository::E1RM_MAX_REPS), $peaks, 'e1rm', $this->weight(...)),
            $this->chart('reps', 'Répétitions max', 'Meilleure série sans charge ajoutée de chaque séance.', $peaks, 'unloadedReps', static fn (float $reps): string => sprintf('%d rep%s', $reps, $reps > 1 ? 's' : '')),
            $this->chart('duration', 'Tenue max', 'Série la plus longue de chaque séance.', $peaks, 'duration', fn (float $seconds): string => $this->units->duration((int) $seconds)),
        ]));

        return [
            'range' => $range,
            'ranges' => $ranges,
            'plan' => $plan['template'] ?? null,
            'start' => $start,
            'sessions' => \count($peaks),
            'charts' => $charts,
        ];
    }

    /**
     * Une courbe : un point par séance qui a quelque chose à en dire, placé sur
     * un axe de **dates** (un trou de trois semaines se voit comme un trou), et
     * sur une échelle **min–max** (une échelle partant de zéro écraserait toute
     * la progression, l'écart de charge étant faible devant la charge).
     *
     * Moins de deux points, pas de courbe : un point unique n'est pas une
     * trajectoire.
     *
     * @param list<array<string, mixed>> $peaks
     * @param callable(float): string    $format
     *
     * @return Chart|null
     */
    private function chart(string $key, string $title, string $hint, array $peaks, string $field, callable $format): ?array
    {
        $kept = array_values(array_filter($peaks, static fn (array $p): bool => $p[$field] > 0));

        if (\count($kept) < 2) {
            return null;
        }

        $values = array_map(static fn (array $p): float => round((float) $p[$field], 1), $kept);
        $min = min($values);
        $max = max($values);
        $span = $max - $min;

        $t0 = $kept[0]['date']->getTimestamp();
        $tSpan = $kept[\count($kept) - 1]['date']->getTimestamp() - $t0;
        $width = 100.0 - 2 * self::X_MARGIN;

        // Le record de la fenêtre : la première séance à l'atteindre, comme
        // partout ailleurs un record appartient à qui l'a posé en premier.
        $bestIndex = (int) array_search($max, $values, true);

        $points = [];
        foreach ($kept as $i => $peak) {
            $value = $values[$i];
            $points[] = [
                'scheduledWorkoutId' => $peak['scheduledWorkoutId'],
                'date' => $peak['date'],
                'value' => $value,
                'label' => $format($value),
                // Deux séances le même jour (ou toutes) : l'axe des dates n'a plus
                // d'étendue, on retombe sur le rang.
                'x' => round(self::X_MARGIN + ($tSpan > 0
                    ? ($peak['date']->getTimestamp() - $t0) / $tSpan
                    : $i / (\count($kept) - 1)) * $width, 2),
                'y' => round($span > 0
                    ? self::Y_BOTTOM - ($value - $min) / $span * (self::Y_BOTTOM - self::Y_TOP)
                    : (self::Y_TOP + self::Y_BOTTOM) / 2, 2),
                'isBest' => $i === $bestIndex,
            ];
        }

        $first = $points[0];
        $last = $points[\count($points) - 1];

        return [
            'key' => $key,
            'title' => $title,
            'hint' => $hint,
            'points' => $points,
            'line' => implode(' ', array_map(static fn (array $p): string => $p['x'].','.$p['y'], $points)),
            // Trois repères, haut, milieu et bas : de quoi lire un ordre de
            // grandeur sans quadriller le dessin. Un seul quand tout est plat.
            'ticks' => $span > 0
                ? [
                    ['y' => self::Y_TOP, 'label' => $format($max)],
                    ['y' => (self::Y_TOP + self::Y_BOTTOM) / 2, 'label' => $format(round(($min + $max) / 2, 1))],
                    ['y' => self::Y_BOTTOM, 'label' => $format($min)],
                ]
                : [['y' => (self::Y_TOP + self::Y_BOTTOM) / 2, 'label' => $format($min)]],
            'first' => $first,
            'last' => $last,
            'best' => $points[$bestIndex],
            'direction' => match (true) {
                $last['value'] > $first['value'] => 'up',
                $last['value'] < $first['value'] => 'down',
                default => 'flat',
            },
        ];
    }

    private function weight(float $kg): string
    {
        return $this->units->weight($kg);
    }
}
