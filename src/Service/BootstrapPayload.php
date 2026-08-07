<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\User;
use App\Repository\CoachingRepository;
use App\Repository\DeletedEntityRepository;
use App\Repository\ExerciseRepository;
use App\Repository\ScheduledWorkoutRepository;

/**
 * L'hydratation complète de la base locale du téléphone, en **une** réponse
 * (KL-14). C'est l'endpoint le plus important du lot 2 : tout ce que l'app fait
 * hors réseau, elle le fait sur ce qu'il a descendu.
 *
 * ## Ce que `?since` réduit, et ce qu'il ne réduit pas
 *
 * Le delta ne s'applique **qu'à la bibliothèque d'exercices** (et à la liste des
 * disparus). La fenêtre de séances datées et l'historique de performance partent
 * toujours en entier, et c'est un choix, pas un raccourci :
 *
 * - **La fraîcheur d'une séance datée n'est mesurable par aucune colonne.** Elle
 *   dépend d'un arbre — `Workout` → `Block` → `PrescribedExercise` →
 *   `PrescribedSet` — dont aucun niveau n'horodate son parent : retoucher une
 *   série ne touche pas le `updatedAt` de la séance, encore moins celui de la
 *   séance datée qui la référence. Un delta sur `ScheduledWorkout.updatedAt`
 *   marcherait à l'essai et manquerait en silence le cas qui compte — le coach
 *   qui corrige le programme de la semaine sur le web. Une fenêtre de 45 jours
 *   est bornée par construction ; un programme périmé sur le téléphone ne l'est
 *   pas.
 * - **L'historique est déjà borné et déjà bon marché** : deux requêtes quel que
 *   soit le nombre d'exercices, c'est précisément ce pour quoi
 *   `PerformanceHistory::bulkFor()` a été écrit (KL-04). Le rendre partiel
 *   laisserait un second appareil, ou une suppression de réalisé faite sur le
 *   web, avec un record fantôme.
 *
 * Ce qui grossit sans borne, c'est la bibliothèque — la globale importée en
 * console, surtout — et elle, elle a un horodatage fiable. C'est donc elle, et
 * elle seule, que le delta allège.
 *
 * ## Ce que la fenêtre ne contient pas
 *
 * Les séances qui ne se consignent pas — celles sans le moindre exercice de
 * renforcement. La règle « le réalisé se logue en muscu, jamais en cardio » vaut
 * aussi à la descente, et le tri se fait ici parce que c'est ici qu'on a les
 * exercices sous la main. Ce qui reste dedans quoi qu'il arrive est écrit dans
 * `TrackableSchedule` — la première ligne, « une séance qui porte du réalisé
 * descend toujours », est ce qui empêche le corollaire ci-dessous de manger un
 * historique.
 *
 * **Corollaire pour le client** : la fenêtre est rendue en clair (`window`), et
 * ce qu'elle contient fait autorité. Une séance datée que le téléphone garde
 * dans cet intervalle et qui n'est pas dans la réponse n'existe plus — déplacée
 * hors fenêtre ou supprimée, le geste local est le même. Les pierres tombales ne
 * servent qu'au reste : les exercices, et les séances datées **hors** fenêtre
 * qu'un client garde en historique.
 *
 * @phpstan-import-type ApiScheduledWorkout from ScheduledWorkoutPayload
 * @phpstan-import-type ApiHistoryEntry from PerformanceHistoryPayload
 *
 * @phpstan-type ApiExercise array{id: int|null, name: string|null, nameEn: string|null, description: string|null, activity: string|null, targetAreas: list<string>, mediaUrl: string|null, global: bool, updatedAt: string|null}
 * @phpstan-type ApiBootstrap array{serverTime: string, since: string|null, exerciseLanguage: string, window: array{from: string, to: string}, exercises: list<ApiExercise>, schedule: list<ApiScheduledWorkout>, history: list<ApiHistoryEntry>, deleted: array{exercises: list<int>, schedule: list<string>}}
 */
final class BootstrapPayload
{
    /** Fenêtre de séances datées descendue sur le téléphone : un mois derrière, deux semaines devant. */
    public const string WINDOW_PAST = 'P30D';
    public const string WINDOW_FUTURE = 'P14D';

    public function __construct(
        private readonly ExerciseRepository $exercises,
        private readonly ScheduledWorkoutRepository $schedule,
        private readonly DeletedEntityRepository $deletedEntities,
        private readonly CoachingRepository $coachings,
        private readonly PerformanceHistory $history,
        private readonly ScheduledWorkoutPayload $scheduledWorkouts,
        private readonly TrackableSchedule $trackable,
    ) {
    }

    /**
     * @return ApiBootstrap
     */
    public function build(User $user, ?\DateTimeImmutable $since, \DateTimeImmutable $now): array
    {
        $owners = $this->libraryOwners($user);

        $today = $now->setTime(0, 0);
        $from = $today->sub(new \DateInterval(self::WINDOW_PAST));
        $to = $today->add(new \DateInterval(self::WINDOW_FUTURE));

        $schedule = [];
        foreach ($this->schedule->findWindowWithContentAndLog($user, $from, $to) as $scheduled) {
            // Le cardio ne descend pas : l'app de suivi ne consigne que la muscu,
            // et une sortie course y occuperait l'écran du jour sans rien pouvoir
            // y écrire. Le filtre est écrit une fois, dans `TrackableSchedule`,
            // qui dit aussi ce qui descend malgré tout — le réalisé déjà écrit et
            // les séances sans prescrit.
            if (!$this->trackable->includes($scheduled)) {
                continue;
            }

            $schedule[] = $this->scheduledWorkouts->build($scheduled);
        }

        return [
            // L'horloge du serveur au moment de la lecture : c'est ce que le
            // client stocke et renvoie en `since` au prochain appel. Se fier à
            // l'heure du téléphone ferait dépendre la synchro d'une pendule qu'on
            // ne contrôle pas.
            'serverTime' => $now->format(\DateTimeInterface::ATOM),
            'since' => $since?->format(\DateTimeInterface::ATOM),
            // La langue d'affichage des noms d'exercices, telle que le compte la
            // règle dans `/profile/settings`. Elle descend ici et pas dans
            // `/api/me` parce que c'est **avec la bibliothèque** qu'elle sert :
            // le téléphone la persiste au même moment que les `nameEn` qu'elle
            // gouverne, et un client qui pull sans jamais appeler `/api/me`
            // afficherait sinon la langue d'origine sans savoir qu'il le fait.
            // Le libellé, lui, reste résolu **côté client** (§8 de
            // `docs/feature-exercise-naming.md`) : le nom qui voyage est celui
            // de la bibliothèque, pas celui d'une préférence figée au pull.
            'exerciseLanguage' => $user->getExerciseLanguage()->value,
            'window' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'exercises' => $this->exercisePayloads($owners, $since),
            'schedule' => $schedule,
            'history' => $this->historyPayloads($user, $owners),
            'deleted' => $this->deletedPayloads($user, $owners, $since),
        ];
    }

    /**
     * Les propriétaires dont les exercices perso sont visibles : soi, ses coachs
     * acceptés et ses athlètes acceptés — plus la bibliothèque globale, que le
     * repository ajoute toujours.
     *
     * **La portée est symétrique, et c'est voulu** : c'est celle de
     * `ExerciseVoter::VIEW`, la seule règle à double sens du projet (`CLAUDE.md`
     * §3). Elle existe parce que le compositeur croise les deux bibliothèques —
     * une séance composée par le coach peut poser ses propres variantes maison
     * dans le programme de l'athlète. Descendre moins que ça sur le téléphone
     * afficherait des exercices sans fiche. Ce n'est pas la portée des index web
     * (`CoachedLibrary`), qui est dirigée : ici on parle de ce qu'on peut
     * **lire**, pas de ce qu'on retrouve dans sa bibliothèque.
     *
     * @return list<User>
     */
    private function libraryOwners(User $user): array
    {
        $owners = [$user];

        foreach ($this->coachings->findAcceptedAthletes($user) as $coaching) {
            $athlete = $coaching->getAthlete();
            if (null !== $athlete) {
                $owners[] = $athlete;
            }
        }

        foreach ($this->coachings->findAcceptedCoaches($user) as $coaching) {
            $coach = $coaching->getCoach();
            if (null !== $coach) {
                $owners[] = $coach;
            }
        }

        return $owners;
    }

    /**
     * @param list<User> $owners
     *
     * @return list<ApiExercise>
     */
    private function exercisePayloads(array $owners, ?\DateTimeImmutable $since): array
    {
        $payloads = [];

        foreach ($this->exercises->findLibraryForUsersChangedSince($owners, $since) as $exercise) {
            $payloads[] = $this->exercisePayload($exercise);
        }

        return $payloads;
    }

    /**
     * @return ApiExercise
     */
    private function exercisePayload(Exercise $exercise): array
    {
        $areas = [];
        foreach ($exercise->getTargetAreas() ?? [] as $area) {
            $areas[] = $area->value;
        }

        return [
            'id' => $exercise->getId(),
            'name' => $exercise->getName(),
            // Le nom anglais, `null` quand le français EST déjà l'anglais
            // (« Dips », « Fartlek »). Additif : un client qui ne le connaît pas
            // l'ignore et continue d'afficher `name`. Le téléphone ne le
            // consomme pas encore.
            'nameEn' => $exercise->getNameEn(),
            'description' => $exercise->getDescription(),
            'activity' => $exercise->getActivity()?->value,
            'targetAreas' => $areas,
            'mediaUrl' => $exercise->getMediaUrl(),
            // Le téléphone ne modifie rien de la bibliothèque, mais il distingue
            // « exercice de l'app » de « mon exercice » à l'affichage.
            'global' => null === $exercise->getOwner(),
            // La même valeur que celle sur laquelle porte le delta : ce que le
            // client relit ici est exactement ce que le serveur a comparé.
            'updatedAt' => ($exercise->getUpdatedAt() ?? $exercise->getCreatedAt())?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Dernière performance et record, par exercice de la bibliothèque visible.
     *
     * **Une liste, pas un objet indexé par identifiant.** `json_encode` rend un
     * tableau PHP en objet ou en liste selon ses clés : un index par id se
     * dégraderait en tableau JSON le jour où les clés seraient 0..n-1, et le
     * client déchiffrerait autre chose sans qu'aucun test ne bronche. Même piège
     * que le `'p' ~ id` de KL-07, réglé ici par une forme qui n'a pas d'ambiguïté.
     *
     * La mise en forme de chaque entrée appartient à `PerformanceHistoryPayload`
     * (KL-17), qui sert aussi `GET /api/exercises/{id}/history` : le client n'a
     * qu'un désérialiseur de performance, parce qu'il n'y a qu'un producteur.
     *
     * @param list<User> $owners
     *
     * @return list<ApiHistoryEntry>
     */
    private function historyPayloads(User $user, array $owners): array
    {
        $entries = [];

        foreach ($this->history->bulkForIds($user, $this->exercises->libraryIdsForUsers($owners)) as $exerciseId => $entry) {
            $entries[] = PerformanceHistoryPayload::entry($exerciseId, $entry['last'], $entry['best']);
        }

        return $entries;
    }

    /**
     * Ce que la base locale doit oublier. **Vide sans `since`** : un jeu complet
     * remplace tout ce que le client avait, il n'a rien à défalquer — et lui
     * envoyer l'intégralité du cimetière serait le seul poste de la réponse qui
     * grandirait avec l'âge de l'installation.
     *
     * @param list<User> $owners
     *
     * @return array{exercises: list<int>, schedule: list<string>}
     */
    private function deletedPayloads(User $user, array $owners, ?\DateTimeImmutable $since): array
    {
        if (null === $since) {
            return ['exercises' => [], 'schedule' => []];
        }

        return [
            'exercises' => $this->deletedEntities->exerciseIdsDeletedSince($owners, $since),
            'schedule' => $this->deletedEntities->scheduleUuidsDeletedSince($user, $since),
        ];
    }
}
