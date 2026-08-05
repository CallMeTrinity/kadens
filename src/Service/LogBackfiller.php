<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Enum\ActivityType;
use App\Enum\PrescriptionType;
use App\Enum\SetType;

/**
 * Le réalisé qu'on n'a pas logué : reconstruire, à partir du prescrit, le
 * réalisé d'une séance de muscu **déjà cochée « Faite »** avant que l'app mobile
 * existe.
 *
 * ## Ce que ça vaut, et ce que ça ne vaut pas
 *
 * Le projet tient partout la règle inverse : `PerformanceHistory` ne lit QUE le
 * réalisé, « un record est un fait, pas une intention ». Ce service fabrique du
 * fait à partir d'une intention, et il n'y a **aucun moyen de distinguer ensuite**
 * ce qu'il a écrit d'un réalisé venu du téléphone. C'est une décision assumée
 * (les séances passées sont considérées valides telles que prescrites) : la
 * commande qui l'appelle est manuelle, hors app, et sa contrepartie est un
 * `--dry-run` par défaut.
 *
 * ## La mise à plat
 *
 * Le prescrit est un arbre (blocs → exercices → séries), le réalisé une liste
 * plate d'exercices. On aplatit dans l'ordre de lecture, en écartant :
 *
 * - **tout ce qui n'est pas `gym`** — un footing de retour au calme dans une
 *   séance mixte ne se logue pas, c'est la règle §3 du projet ;
 * - **les types de prescription qui ne décrivent pas de séries** (`AMRAP`,
 *   `FOR_TIME`…) : il n'y a rien à déduire d'un « le plus de tours possible en
 *   12 minutes » ;
 * - **les lignes non chiffrées** (ni répétitions ni durée). Les copier
 *   créerait du volume fantôme dans `LogMetrics` — une série de travail qui ne
 *   dit rien. Une charge absente, elle, ne gêne pas : c'est le cas normal du
 *   poids du corps, `LoggedSet::getTonnageKg()` rend déjà 0.
 *
 * `Block.rounds` est **multiplié**, comme `WorkoutMetrics::volume()` le fait déjà
 * pour le tonnage prévu : un circuit à trois tours a bien été fait trois fois.
 * Le `rpe` prescrit est recopié sur chaque série, pour la même raison que tout le
 * reste — si on décide que le prescrit décrit ce qui a eu lieu, il le décrit
 * entièrement.
 *
 * Le service **ne persiste rien et ne mute pas la séance** : il construit et rend.
 * C'est ce qui permet à l'appelant d'afficher un dry-run sans qu'un flush égaré
 * n'écrive quoi que ce soit.
 */
final class LogBackfiller
{
    /**
     * Les seuls types dont on sait déduire des séries. Ce sont aussi les seuls
     * que le mode « séries détaillées » accepte (cf. `CLAUDE.md` §3).
     */
    private const array LOGGABLE = [PrescriptionType::SETS_REPS, PrescriptionType::SETS_TIME];

    /**
     * Le réalisé qu'on écrirait pour cette séance, ou une liste vide s'il n'y a
     * rien à en tirer.
     *
     * Vide veut dire trois choses différentes, et l'appelant n'a pas à les
     * distinguer : pas de prescrit, un réalisé déjà là (on ne remplace jamais un
     * fait par une déduction), ou un prescrit sans aucune ligne de force.
     *
     * @return list<LoggedExercise>
     */
    public function build(ScheduledWorkout $scheduled): array
    {
        $workout = $scheduled->getWorkout();

        if (null === $workout || $scheduled->hasLog()) {
            return [];
        }

        $logged = [];

        foreach ($workout->getBlocks() as $block) {
            $rounds = max(1, $block->getRounds() ?? 1);

            foreach ($block->getPrescribedExercises() as $prescribed) {
                $exercise = $prescribed->getExercise();

                if (ActivityType::GYM !== $exercise?->getActivity()) {
                    continue;
                }

                if (!\in_array($prescribed->getPrescriptionType(), self::LOGGABLE, true)) {
                    continue;
                }

                $sets = $this->setsOf($prescribed, $rounds);

                if ([] === $sets) {
                    continue;
                }

                $entry = (new LoggedExercise())
                    ->setExercise($exercise)
                    // Snapshot du nom, comme le fait `LogIngestor` : il survit à
                    // la disparition de l'exercice de la bibliothèque.
                    ->setExerciseName((string) $exercise->getName())
                    ->setSourcePrescribedExercise($prescribed)
                    // Le rang vient de l'ordre d'aplatissement, jamais de la
                    // position dans le bloc : deux blocs repartiraient de zéro.
                    ->setPosition(\count($logged));

                foreach ($sets as $set) {
                    $entry->addLoggedSet($set);
                }

                $logged[] = $entry;
            }
        }

        return $logged;
    }

    /**
     * Les séries réalisées d'une ligne du programme, tous tours confondus.
     *
     * Un tour est reconstruit **à neuf** à chaque passage : une série porte un
     * `uuid` unique en base, donc dupliquer les instances d'un premier tour
     * échouerait à l'insertion.
     *
     * @return list<LoggedSet>
     */
    private function setsOf(PrescribedExercise $prescribed, int $rounds): array
    {
        $sets = [];

        for ($round = 0; $round < $rounds; ++$round) {
            foreach ($this->oneRound($prescribed) as $set) {
                $sets[] = $set->setPosition(\count($sets));
            }
        }

        return $sets;
    }

    /**
     * Un tour, dans le mode où l'exercice est décrit : les séries détaillées si
     * elles existent — elles priment sur le compteur scalaire, comme partout —
     * sinon `sets` séries ordinaires identiques.
     *
     * @return list<LoggedSet>
     */
    private function oneRound(PrescribedExercise $prescribed): array
    {
        $rpe = $prescribed->getRpe();

        if ($prescribed->hasDetailedSets()) {
            $sets = [];

            foreach ($prescribed->getDetailedSets() as $detailed) {
                if (null === $detailed->getReps() && null === $detailed->getDurationSeconds()) {
                    continue;
                }

                // Le type est conservé tel quel : un échauffement prescrit reste
                // un échauffement réalisé, donc reste hors du volume de travail.
                $sets[] = (new LoggedSet())
                    ->setSetType($detailed->getSetType())
                    ->setReps($detailed->getReps())
                    ->setWeightKg($detailed->getWeightKg())
                    ->setDurationSeconds($detailed->getDurationSeconds())
                    ->setRpe($rpe);
            }

            return $sets;
        }

        $count = $prescribed->getSets() ?? 1;

        if ($count < 1 || (null === $prescribed->getReps() && null === $prescribed->getDurationSeconds())) {
            return [];
        }

        $sets = [];

        for ($i = 0; $i < $count; ++$i) {
            $sets[] = (new LoggedSet())
                ->setSetType(SetType::NORMAL)
                ->setReps($prescribed->getReps())
                ->setWeightKg($prescribed->getWeightKg())
                ->setDurationSeconds($prescribed->getDurationSeconds())
                ->setRpe($rpe);
        }

        return $sets;
    }
}
