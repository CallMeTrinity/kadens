<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\ScheduledWorkout;
use App\Enum\ScheduledStatus;
use App\Repository\ScheduledWorkoutRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La reprise d'un historique d'entraînement exporté par une autre application :
 * une séance de l'export devient une **séance datée cochée « Faite »**, portant
 * son réalisé série par série.
 *
 * Le service ne connaît **aucun format de fichier**. Il consomme la structure
 * plate que rendent les parseurs (`BlastCsvParser`, `FitNotesCsvParser`), et
 * c'est ce qui lui permet de servir plusieurs sources sans se dédoubler : ce qui
 * change d'une application à l'autre, c'est la lecture, pas l'écriture.
 *
 * ## Une séance libre, pas une séance de bibliothèque
 *
 * Chaque séance importée est créée avec `workout = null` et son titre en
 * snapshot : c'est exactement ce que le modèle appelle une séance libre
 * (`getDisplayTitle()` sait déjà le lire, `docs/feature-live-tracking.md` §KL-33).
 *
 * Le choix est structurant et il vaut mieux le poser une fois. On pourrait
 * fabriquer un `Workout` prescrit par séance, en recopiant le réalisé dedans.
 * Ce serait faux à deux titres :
 *
 * - **ça inventerait une intention.** Ces séances n'ont jamais été prescrites
 *   dans Kadens. `LogComparator` afficherait « Tenu » sur les 5 000 séries,
 *   c'est-à-dire une mesure d'écart qui ne mesure rien. Le projet tient partout
 *   la frontière prescrit/réalisé, la brouiller ici la brouillerait pour tout ce
 *   qui lit ces séances ensuite ;
 * - **ça noierait la bibliothèque** sous 300 séances qu'on ne rejouera jamais.
 *
 * C'est l'exact inverse de `LogBackfiller`, qui déduit le réalisé d'un prescrit
 * existant. Ici on a le fait, pas l'intention, et on écrit le fait.
 *
 * ## La rejouabilité
 *
 * Une commande d'import se relance : sur un fichier corrigé, après un mapping
 * complété, sur un export élargi. Elle ne doit pas empiler 300 séances de plus.
 *
 * Les identifiants sont donc **déterministes** : un UUIDv5 dérivé d'un namespace
 * fixe et de la clé source — ce que l'export répète à l'identique sur toutes les
 * lignes d'une même séance (son horodatage chez Blast, son jour chez FitNotes).
 * La même séance retombe sur le même `uuid`, et l'unicité de
 * `uniq_scheduled_workout_uuid` devient une garantie plutôt qu'un obstacle.
 * C'est le même mécanisme qui rend `PUT /api/schedule/{uuid}` idempotent pour le
 * téléphone, appliqué à une source qui n'émet pas d'uuid.
 *
 * Le namespace étant **commun à toutes les sources**, une clé source porte le nom
 * de la sienne (`fitnotes|2025-09-24`) : deux applications qui numéroteraient
 * leurs séances pareil se marcheraient dessus sinon, et l'import de la seconde
 * effacerait l'historique de la première sans rien dire. Blast fait exception et
 * garde son horodatage nu — le préfixer aujourd'hui changerait tous ses uuid,
 * donc réimporterait son historique en double au lieu de le remplacer.
 *
 * Une séance déjà importée est **remplacée**, jamais dupliquée, et le
 * remplacement se fait en deux temps : `purge()` efface et flushe, `import()`
 * réécrit. Les deux appels ne sont pas une maladresse, ils sont la condition de
 * correction — exactement comme dans `LogIngestor`. Doctrine ordonne un flush en
 * insertions puis suppressions ; réécrire dans un seul flush enverrait donc
 * l'`INSERT` d'une série avant le `DELETE` de celle qu'elle remplace, et comme
 * les uuid sont déterministes, ce sont les **mêmes** uuid. Erreur d'unicité sur
 * `uniq_logged_set_uuid`, sur le cas le plus normal de la commande : la relancer.
 *
 * ## Ce que l'import ne prétend pas savoir
 *
 * - **L'échauffement.** L'export n'a pas la notion : toutes les séries comptent
 *   comme travail, le tonnage historique est donc un peu surévalué. Non
 *   rattrapable, la donnée n'existe pas.
 * - **L'unilatéral.** Blast enregistre la charge et les répétitions **par
 *   côté**. On importe tel quel : doubler fabriquerait une mesure qui n'a pas eu
 *   lieu, et décrocherait de ce que l'utilisateur saisira demain dans Kadens,
 *   qui n'a pas non plus de champ « unilatéral ». Conséquence assumée :
 *   `LogMetrics` sous-compte ces séries, tandis que `PerformanceHistory` et
 *   `ExerciseTrajectory` restent justes puisque l'unilatéral est mappé sur une
 *   entrée distincte de la bibliothèque.
 * - **Le RPE.** Blast exporte la colonne et elle vaut zéro partout, FitNotes ne
 *   l'a pas. Elle reste `null`.
 *
 * ## Les notes, et ce qu'elles rattrapent
 *
 * Un parseur peut poser un texte libre sur une entrée (`entries[].notes`), qui
 * atterrit dans `LoggedExercise.notes` et s'affiche tel quel
 * (`components/_log_exrow.html.twig`). C'est le fourre-tout de ce que l'export
 * porte et que le modèle n'a **pas de colonne** pour tenir : le commentaire que
 * l'utilisateur a écrit ce jour-là, la distance d'un port de charge
 * (`LoggedSet` n'a ni distance ni allure). Écrit en toutes lettres, ce n'est pas
 * exploitable statistiquement, mais c'est lisible — et l'alternative était de le
 * jeter. À ne pas confondre avec `Workout.notes` / `PlanTemplate.notes`, qui sont
 * le bloc-notes privé du propriétaire (`CLAUDE.md` §3) : ici on ne fait que
 * recopier ce que la source dit déjà.
 */
final class TrainingHistoryImporter
{
    /**
     * Namespace des uuid dérivés. Une constante arbitraire mais **figée** : la
     * changer ferait réimporter tout l'historique en double.
     */
    private const string NAMESPACE = '6f1a9c1e-5b6d-5c2a-9f3e-2b7c4d8a1e05';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScheduledWorkoutRepository $scheduled,
    ) {
    }

    /**
     * Efface les séances déjà importées pour ces clés source, **et flushe**.
     *
     * Le flush est dans la méthode, pas laissé à l'appelant : c'est lui qui rend
     * la réécriture possible (cf. l'en-tête de classe), donc l'oublier est un
     * bug silencieux jusqu'à la première relance. Une méthode qui écrit doit le
     * dire dans son nom et le faire entièrement.
     *
     * La suppression est en cascade côté base (`LoggedExercise` et `LoggedSet`
     * sont en `ON DELETE CASCADE`), donc effacer la séance suffit.
     *
     * @param list<string> $sourceKeys
     *
     * @return int nombre de séances effacées
     */
    public function purge(array $sourceKeys): int
    {
        $existing = $this->existingFor($sourceKeys);

        foreach ($existing as $workout) {
            $this->em->remove($workout);
        }

        if ([] !== $existing) {
            $this->em->flush();
        }

        return \count($existing);
    }

    /**
     * Écrit une séance de l'export. Rend le compte de ce qui a été posé, ou
     * `null` si la séance n'a rien de logable une fois le mapping appliqué.
     *
     * La séance est **toujours neuve** : c'est `purge()` qui a retiré une
     * éventuelle version antérieure, et l'uuid déterministe qui garantit qu'elle
     * reprend la même identité. Rien n'est flushé ici, ce qui laisse le dry-run
     * emprunter exactement le même chemin que l'écriture.
     *
     * @param array{
     *     sourceKey: string,
     *     title: string,
     *     startedAt: \DateTimeImmutable|null,
     *     endedAt: \DateTimeImmutable|null,
     *     loggedAt: \DateTimeImmutable,
     *     date: \DateTimeImmutable,
     *     entries: list<array{key: string, name: string, notes?: string|null, sets: list<array{setType: \App\Enum\SetType, reps: int|null, weightKg: float|null, durationSeconds: int|null}>}>
     * } $session
     *
     * @return array{workout: ScheduledWorkout, exercises: int, sets: int}|null
     */
    public function import(array $session, ImportedExerciseMap $map): ?array
    {
        $workout = (new ScheduledWorkout(self::uuidFor($session['sourceKey'])))
            ->setOwner($map->getOwner())
            ->setTitle($session['title'])
            ->setScheduledDate($session['date'])
            ->setStatus(ScheduledStatus::DONE)
            ->setStartedAt($session['startedAt'])
            ->setEndedAt($session['endedAt']);

        $exercises = 0;
        $sets = 0;

        foreach ($session['entries'] as $entry) {
            $exercise = $map->resolve($entry['key']);

            // Clé volontairement ignorée (le cardio) : on saute l'exercice sans
            // toucher au reste de la séance, qui peut très bien être de la muscu.
            if (null === $exercise) {
                continue;
            }

            $logged = (new LoggedExercise())
                ->setExercise($exercise)
                // Le snapshot porte le nom **Kadens**, pas le libellé Blast :
                // c'est ce que l'utilisateur lira, et ce qui restera cohérent
                // avec le reste de son historique si l'exercice disparaît.
                ->setExerciseName((string) $exercise->getName())
                ->setPosition($exercises)
                // Le texte que la source portait et que le modèle ne sait pas
                // ranger ailleurs (cf. l'en-tête de classe). Absent chez Blast.
                ->setNotes(('' === trim((string) ($entry['notes'] ?? ''))) ? null : trim((string) $entry['notes']));

            foreach ($entry['sets'] as $index => $set) {
                $logged->addLoggedSet(
                    (new LoggedSet(self::uuidFor($session['sourceKey'] . '|' . $entry['key'] . '|' . $exercises . '|' . $index)))
                        ->setPosition($index)
                        ->setSetType($set['setType'])
                        ->setReps($set['reps'])
                        ->setWeightKg($set['weightKg'])
                        ->setDurationSeconds($set['durationSeconds'])
                        // L'export ne date pas ses séries, seulement la séance.
                        // Inventer une progression dans l'heure serait faux, d'où
                        // un instant unique pour toute la séance. C'est `loggedAt`
                        // et pas `startedAt` parce qu'une source peut n'exporter
                        // aucune heure (FitNotes) : il faut alors quand même un
                        // instant pour `LogMetrics::loggedAt()`, sans pour autant
                        // prétendre savoir à quelle heure la séance a commencé.
                        ->setCompletedAt($session['loggedAt']),
                );

                ++$sets;
            }

            $workout->addLoggedExercise($logged);
            ++$exercises;
        }

        if (0 === $exercises) {
            // Rien de logable : une séance de cardio pur, ou dont toutes les
            // clés sont ignorées. On ne pose pas une séance vide au calendrier —
            // et comme elle n'a jamais été persistée, il n'y a rien à défaire.
            return null;
        }

        $this->em->persist($workout);

        return ['workout' => $workout, 'exercises' => $exercises, 'sets' => $sets];
    }

    /**
     * Les séances déjà importées qui portent un uuid dérivé de ces clés source.
     * Sert au rapport (« combien seront remplacées »), avant toute écriture.
     *
     * @param list<string> $sourceKeys
     *
     * @return list<ScheduledWorkout>
     */
    public function existingFor(array $sourceKeys): array
    {
        if ([] === $sourceKeys) {
            return [];
        }

        return $this->scheduled->findBy(['uuid' => array_map(self::uuidFor(...), $sourceKeys)]);
    }

    /**
     * L'uuid déterministe d'une clé source. Public pour que la commande puisse
     * l'afficher, et pour qu'un test puisse vérifier la stabilité.
     */
    public static function uuidFor(string $sourceKey): Uuid
    {
        return Uuid::v5(Uuid::fromString(self::NAMESPACE), $sourceKey);
    }
}
