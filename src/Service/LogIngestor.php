<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\ScheduledStatus;
use App\Http\IsoDate;
use App\Http\LoggedExerciseInput;
use App\Http\ScheduledWorkoutInput;
use App\Repository\ExerciseRepository;
use App\Repository\LoggedSetRepository;
use App\Security\Voter\ExerciseVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * L'écriture du réalisé venu du téléphone (KL-16) : un document complet arrive,
 * une séance datée en ressort.
 *
 * ## Le remplacement est total, et il se fait en deux temps
 *
 * Le téléphone fait autorité sur le réalisé (§0.3 point 1) : ce que le document
 * décrit **remplace** ce que la séance portait, il ne s'y ajoute pas. On efface
 * donc tout le réalisé, on flush, puis on écrit le nouveau.
 *
 * Les deux `flush()` ne sont pas une maladresse, ils sont la condition de
 * correction :
 *
 * - **L'unicité des `uuid` de série.** Doctrine ordonne un `flush()` en
 *   insertions, puis mises à jour, puis suppressions. Effacer et réécrire la même
 *   série dans un seul flush enverrait donc l'`INSERT` avant le `DELETE` : erreur
 *   d'unicité sur `uniq_logged_set_uuid`, c'est-à-dire une 500 sur le cas le plus
 *   normal du ticket — un document rejoué.
 * - **Le piège de l'`orphanRemoval`.** L'alternative « réconcilier les lignes
 *   existantes par uuid » demanderait de déplacer une série d'un `LoggedExercise`
 *   à un autre. Retirer un élément d'une collection en `orphanRemoval` le
 *   programme pour suppression, même si on l'ajoute ensuite ailleurs : la série
 *   déplacée disparaîtrait. Une perte de données silencieuse, sur un chemin rare
 *   — le pire des deux mondes.
 *
 * Le prix est quelques `DELETE`/`INSERT` de plus sur des dizaines de lignes. Le
 * gain est un invariant qu'on peut relire : après l'appel, le réalisé de la
 * séance **est** le document, sans condition.
 *
 * ## Ce qu'on ne touche pas
 *
 * Ni `workout`, ni `sourcePlanTemplate`, ni `sourcePlanItem`, ni `planAnchorDate`,
 * ni la date. Le prescrit ne bouge jamais (`CLAUDE.md` §3), et le rattachement au
 * plan appartient au web. Une séance de plan qu'on remplit reste une séance de
 * plan : c'est ce qui permet au `resync` de la retrouver.
 *
 * ## Les références sont vérifiées avant d'écrire
 *
 * Un `exerciseId` invisible ou un `sourcePrescribedId` qui désigne la ligne du
 * programme d'une **autre** séance sont refusés en 422, avec le chemin du champ
 * fautif. Les rattacher silencieusement à `null` serait pire que l'erreur : le
 * réalisé resterait lisible, mais il sortirait de l'historique et des records
 * sans que rien ne le signale.
 */
final class LogIngestor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExerciseRepository $exercises,
        private readonly LoggedSetRepository $loggedSets,
        private readonly Security $security,
    ) {
    }

    /**
     * Crée la séance datée si l'`uuid` est inconnu, met à jour son réalisé sinon.
     *
     * La garde d'accès n'est **pas** ici : le contrôleur teste `LOG` sur une
     * séance existante, et la propriété d'une séance créée découle du jeton. Ce
     * service ne décide de rien, il applique.
     */
    public function upsert(User $owner, Uuid $uuid, ScheduledWorkoutInput $input, ?ScheduledWorkout $existing): ScheduledWorkout
    {
        $scheduled = $existing ?? $this->create($owner, $uuid, $input);

        $this->assertUuidMatches($uuid, $input);
        $references = $this->resolveReferences($scheduled, $input);
        $this->assertSetUuidsAreFree($scheduled, $input);

        $this->em->wrapInTransaction(function () use ($scheduled, $input, $references): void {
            $this->applyMeta($scheduled, $input);

            // Premier temps : le réalisé précédent s'en va, et il s'en va
            // *vraiment* (cf. en-tête) avant qu'on réécrive les mêmes uuid.
            foreach ($scheduled->getLoggedExercises()->toArray() as $logged) {
                $scheduled->removeLoggedExercise($logged);
            }

            $this->em->persist($scheduled);
            $this->em->flush();

            // Second temps : le document devient le réalisé.
            foreach ($input->log as $position => $loggedInput) {
                $scheduled->addLoggedExercise($this->buildExercise($loggedInput, $position, $references));
            }

            $this->em->flush();
        });

        return $scheduled;
    }

    /**
     * Une séance **libre** : sans source, à la date du document, au nom qu'il
     * porte. Le serveur ne crée aucun `Workout` en bibliothèque — c'est une
     * décision actée du ticket, et c'est ce qui distingue « j'ai improvisé une
     * séance » de « j'ai écrit un programme ».
     */
    private function create(User $owner, Uuid $uuid, ScheduledWorkoutInput $input): ScheduledWorkout
    {
        $date = IsoDate::date($input->date);

        if (null === $date) {
            // Inatteignable via le contrôleur (`Assert\Date` a déjà tranché),
            // mais l'entité exige une date non nulle : mieux vaut une violation
            // qu'un `TypeError`.
            throw $this->violation('date', 'La date doit être au format AAAA-MM-JJ.', $input->date);
        }

        return (new ScheduledWorkout($uuid))
            ->setOwner($owner)
            ->setTitle($input->title)
            ->setScheduledDate($date);
    }

    /**
     * Ce que le document peut écrire hors du réalisé, et rien de plus (cf.
     * l'en-tête de `ScheduledWorkoutInput` pour le partage d'autorité).
     */
    private function applyMeta(ScheduledWorkout $scheduled, ScheduledWorkoutInput $input): void
    {
        $scheduled->setStartedAt(IsoDate::dateTime($input->startedAt));
        $scheduled->setEndedAt(IsoDate::dateTime($input->endedAt));

        if ($input->closes()) {
            $scheduled->setStatus(ScheduledStatus::DONE);
        }

        if (null !== $input->completionNotes && '' !== trim($input->completionNotes)) {
            $scheduled->setCompletionNotes($input->completionNotes);
        }
    }

    /**
     * @param array{exercises: array<int, Exercise>, prescribed: array<int, PrescribedExercise>} $references
     */
    private function buildExercise(LoggedExerciseInput $input, int $position, array $references): LoggedExercise
    {
        $exercise = null === $input->exerciseId ? null : $references['exercises'][$input->exerciseId];
        $prescribed = null === $input->sourcePrescribedId ? null : $references['prescribed'][$input->sourcePrescribedId];

        $logged = (new LoggedExercise())
            ->setExercise($exercise)
            // `resolveReferences` a déjà refusé le cas où les trois sources du
            // nom manquent : ici il ne peut plus être null.
            ->setExerciseName((string) self::snapshotName($input, $exercise, $prescribed))
            ->setSourcePrescribedExercise($prescribed)
            // Le rang vient de l'ORDRE de la liste, jamais d'un champ : une
            // seule source pour un seul fait.
            ->setPosition($position)
            ->setSkipped($input->skipped)
            ->setNotes($input->notes);

        foreach ($input->sets as $rank => $setInput) {
            $logged->addLoggedSet(
                (new LoggedSet(Uuid::fromString((string) $setInput->uuid)))
                    ->setPosition($rank)
                    ->setSetType($setInput->setType())
                    ->setReps($setInput->reps)
                    ->setWeightKg($setInput->weightKg)
                    ->setDurationSeconds($setInput->durationSeconds)
                    ->setRpe($setInput->rpe)
                    ->setCompletedAt(IsoDate::dateTime($setInput->completedAt)),
            );
        }

        return $logged;
    }

    /**
     * Charge et vérifie d'un coup toutes les références du document : deux
     * requêtes au plus, quel que soit le nombre d'exercices.
     *
     * Toutes les violations sont collectées avant d'être levées ensemble. Un
     * client hors réseau qui corrige un champ à la fois ferait un aller-retour
     * par erreur ; il n'y a aucune raison de les lui donner au compte-gouttes.
     *
     * @return array{exercises: array<int, Exercise>, prescribed: array<int, PrescribedExercise>}
     */
    private function resolveReferences(ScheduledWorkout $scheduled, ScheduledWorkoutInput $input): array
    {
        $violations = new ConstraintViolationList();

        $exercises = $this->resolveExercises($input, $violations);
        $prescribed = $this->prescribedOfWorkout($scheduled);
        $kept = [];

        foreach ($input->log as $position => $loggedInput) {
            $prescribedId = $loggedInput->sourcePrescribedId;

            if (null !== $prescribedId) {
                if (!isset($prescribed[$prescribedId])) {
                    // La ligne du programme d'une AUTRE séance : l'accepter
                    // ferait apparier par `LogComparator` un réalisé et un
                    // prescrit qui n'ont jamais été sur la même page.
                    $violations->add($this->violationOf(
                        \sprintf('log[%d].sourcePrescribedId', $position),
                        'Cette ligne de programme n\'appartient pas à la séance.',
                        $prescribedId,
                    ));
                } else {
                    $kept[$prescribedId] = $prescribed[$prescribedId];
                }
            }

            $name = self::snapshotName(
                $loggedInput,
                null === $loggedInput->exerciseId ? null : ($exercises[$loggedInput->exerciseId] ?? null),
                null === $prescribedId ? null : ($prescribed[$prescribedId] ?? null),
            );

            if (null === $name) {
                $violations->add($this->violationOf(
                    \sprintf('log[%d].name', $position),
                    'Le nom de l\'exercice est requis quand aucun exercice de la bibliothèque n\'est référencé.',
                    null,
                ));
            }
        }

        if ($violations->count() > 0) {
            throw new ValidationFailedException($input, $violations);
        }

        return ['exercises' => $exercises, 'prescribed' => $kept];
    }

    /**
     * Le snapshot du nom, ou **null** si rien ne permet de le former.
     *
     * L'ordre dit la règle : ce que le client a saisi d'abord (il a pu remplacer
     * l'exercice par un autre au pied de la machine), puis le nom vivant de
     * l'exercice de bibliothèque, puis celui de la ligne du programme. Une chaîne
     * vide n'est pas un nom — sans ça, un champ laissé blanc par le client
     * s'écrirait en base et resterait affiché tel quel pour toujours.
     *
     * Écrit une seule fois pour être appelé deux fois : la validation et la
     * construction doivent conclure la même chose, sinon on refuserait un
     * document qu'on sait remplir, ou l'inverse.
     */
    private static function snapshotName(LoggedExerciseInput $input, ?Exercise $exercise, ?PrescribedExercise $prescribed): ?string
    {
        foreach ([$input->name, $exercise?->getName(), $prescribed?->getExercise()?->getName()] as $candidate) {
            if (null !== $candidate && '' !== trim($candidate)) {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Les exercices référencés, vérifiés **un par un** contre
     * `ExerciseVoter::VIEW`.
     *
     * La portée est celle du voter et pas une requête maison : c'est la seule
     * règle symétrique du projet (soi, ses coachs, ses athlètes, plus la
     * bibliothèque globale), et la réécrire ici en DQL ferait un second endroit
     * qui décide de ce qu'on a le droit de lire. Le coût est nul en pratique —
     * `CoachingResolver` mémoïse, et une séance référence une dizaine
     * d'exercices.
     *
     * @return array<int, Exercise>
     */
    private function resolveExercises(ScheduledWorkoutInput $input, ConstraintViolationList $violations): array
    {
        $ids = [];
        foreach ($input->log as $loggedInput) {
            if (null !== $loggedInput->exerciseId) {
                $ids[$loggedInput->exerciseId] = true;
            }
        }

        if ([] === $ids) {
            return [];
        }

        $found = [];
        foreach ($this->exercises->findBy(['id' => array_keys($ids)]) as $exercise) {
            if ($this->security->isGranted(ExerciseVoter::VIEW, $exercise)) {
                $found[(int) $exercise->getId()] = $exercise;
            }
        }

        foreach ($input->log as $position => $loggedInput) {
            $id = $loggedInput->exerciseId;

            if (null !== $id && !isset($found[$id])) {
                // Inconnu et interdit rendent la même violation : distinguer
                // ferait de l'API un oracle sur la bibliothèque des autres.
                $violations->add($this->violationOf(
                    \sprintf('log[%d].exerciseId', $position),
                    'Exercice inconnu ou inaccessible.',
                    $id,
                ));
            }
        }

        return $found;
    }

    /**
     * Les lignes du programme de CETTE séance datée, indexées par identifiant.
     * Aucune requête : le repository a déjà descendu l'arbre (`withPrescribed`).
     *
     * @return array<int, PrescribedExercise>
     */
    private function prescribedOfWorkout(ScheduledWorkout $scheduled): array
    {
        $index = [];

        foreach ($scheduled->getWorkout()?->getBlocks() ?? [] as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                $index[(int) $prescribed->getId()] = $prescribed;
            }
        }

        return $index;
    }

    /**
     * Un `uuid` de série ne peut pas être emprunté à une autre séance datée.
     *
     * Sans ce contrôle, le cas sortirait en 500 par violation de contrainte
     * d'unicité — au mieux. Au pire, dans une autre implémentation, il
     * déplacerait la série d'une séance à l'autre : un client qui recycle ses
     * identifiants effacerait le réalisé d'une séance qu'il ne visait pas. 409
     * plutôt que 422 : le document n'est pas malformé, il entre en conflit avec
     * un état existant, et le client doit régénérer l'identifiant, pas corriger
     * un champ.
     */
    private function assertSetUuidsAreFree(ScheduledWorkout $scheduled, ScheduledWorkoutInput $input): void
    {
        $uuids = [];
        foreach ($input->log as $loggedInput) {
            foreach ($loggedInput->sets as $setInput) {
                $uuids[] = Uuid::fromString((string) $setInput->uuid);
            }
        }

        if ([] === $uuids) {
            return;
        }

        foreach ($this->loggedSets->findBy(['uuid' => $uuids]) as $set) {
            if ($set->getLoggedExercise()?->getScheduledWorkout() !== $scheduled) {
                throw new ConflictHttpException(\sprintf(
                    'La série %s appartient déjà à une autre séance.',
                    (string) $set->getUuid(),
                ));
            }
        }
    }

    /** L'identifiant du corps, s'il est là, doit être celui de l'URL. */
    private function assertUuidMatches(Uuid $uuid, ScheduledWorkoutInput $input): void
    {
        if (null !== $input->uuid && !$uuid->equals(Uuid::fromString($input->uuid))) {
            throw $this->violation('uuid', 'L\'identifiant du corps ne correspond pas à celui de l\'URL.', $input->uuid);
        }
    }

    private function violation(string $path, string $message, mixed $invalidValue): ValidationFailedException
    {
        $violations = new ConstraintViolationList();
        $violations->add($this->violationOf($path, $message, $invalidValue));

        return new ValidationFailedException(null, $violations);
    }

    /**
     * Une violation « faite à la main », pour ce qu'aucune contrainte d'attribut
     * ne peut vérifier : l'existence et la portée d'une référence.
     *
     * Elle emprunte la route de `ApiExceptionListener` (KL-13), qui cherche la
     * `ValidationFailedException` dans toute la chaîne des causes et rend un 422
     * avec la liste des champs. Le client n'a donc **qu'un** format d'erreur de
     * validation à lire, qu'elle vienne d'un attribut ou d'ici.
     */
    private function violationOf(string $path, string $message, mixed $invalidValue): ConstraintViolation
    {
        return new ConstraintViolation($message, null, [], null, $path, $invalidValue);
    }
}
