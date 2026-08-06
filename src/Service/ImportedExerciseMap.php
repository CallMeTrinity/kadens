<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\TargetArea;
use App\Repository\ExerciseRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La correspondance entre les exercices d'un export tiers et la bibliothèque
 * Kadens. C'est **la pièce manuelle** de l'import, et la seule qui décide de sa
 * valeur.
 *
 * Le service est indépendant de la source : il ne voit que des clés, celles que
 * le parseur du format a fabriquées (`BlastCsvParser::key()` croise nom +
 * équipement + exécution, `FitNotesCsvParser` n'a que le nom). Un fichier de
 * correspondance par export, un service pour tous.
 *
 * ## Pourquoi c'est écrit à la main
 *
 * Sur les 127 clés des trois exports Blast, huit seulement retombent sur un nom
 * de la bibliothèque globale par simple normalisation : les formulations
 * diffèrent (`Curl marteau` contre `Curl marteau (Hammer curl)`, `Extension de
 * mollet assis` contre `Extension des mollets assis à la machine`). FitNotes est
 * pire encore, ses libellés étant en anglais (`Lat Pulldown`, `Seated Cable
 * Row`) sur une bibliothèque francophone. Aucun appariement automatique par
 * similarité ne tient sur ce volume, et un faux positif est bien pire qu'un
 * trou : il attribuerait des séries à un mouvement jamais fait, dans un
 * historique qu'on ne relira plus jamais ligne à ligne.
 *
 * Le service propose donc des candidats (`suggestionsFor`), il n'en choisit
 * aucun. Le fichier reste versionné dans `data/`, comme `exercises.json`.
 *
 * ## Pourquoi le lien compte
 *
 * `LoggedExercise.exercise` est nullable, et une séance sans lien reste lisible
 * à l'écran grâce au snapshot du nom. Mais `LogMetrics`, `PerformanceHistory`,
 * `ExerciseTrajectory` et `LoggedExerciseRepository::usageForOwner()` requêtent
 * **tous** par `exercise_id`. Un import non mappé produirait donc un historique
 * consultable et statistiquement inerte : aucune trajectoire, aucun record,
 * aucun volume par zone. C'est tout l'intérêt de l'opération.
 *
 * ## Le format
 *
 * Un objet JSON dont chaque clé est celle produite par le parseur de la source.
 * Quatre valeurs possibles, et l'ambiguïté est exclue par construction :
 *
 * - `"Nom exact d'un exercice"` — appariement à la bibliothèque ;
 * - `null` — séries volontairement écartées (le cardio, que le modèle ne logue
 *   pas : `LoggedSet` n'a ni distance ni allure) ;
 * - `""` — **non tranché**. L'import refuse d'écrire tant qu'il en reste une :
 *   un oubli doit bloquer, pas produire un trou silencieux ;
 * - `{"create": {"name": "…", "activity": "gym", "targetAreas": ["biceps"]}}` —
 *   l'exercice n'existe pas dans la bibliothèque et doit être créé **en perso**,
 *   chez le propriétaire de l'import. Jamais en global : la bibliothèque globale
 *   s'alimente par `app:import-exercises` et par un `ROLE_ADMIN` dans l'app,
 *   pas par le rattrapage d'un historique personnel (`CLAUDE.md` §3).
 *
 * Le `name` d'un `create` est **obligatoire**, et c'est le libellé Kadens, pas
 * celui de Blast. Le déduire du nom source paraissait économique et fusionnait
 * silencieusement des exercices que la clé distingue : `Développé incliné`
 * s'appelle pareil aux haltères, à la barre guidée et à la machine convergente.
 * Un nom explicite par clé est la seule façon de tenir la règle « variantes =
 * entrées distinctes » jusqu'au bout.
 *
 * Une clé absente du fichier vaut `""` : ajouter un export ne doit pas passer
 * ses nouveaux exercices à la trappe.
 */
final class ImportedExerciseMap
{
    /** Non tranché : présent dans l'export, pas encore décidé. */
    public const string UNRESOLVED = '';

    /**
     * @param array<string, string|null|array{create: array{name?: string, activity?: string, targetAreas?: list<string>, description?: string}}> $entries
     * @param array<string, Exercise>                                                                                             $library    indexé par nom
     */
    private function __construct(
        private readonly array $entries,
        private readonly array $library,
        private readonly EntityManagerInterface $em,
        private readonly User $owner,
        /** @var array<string, Exercise> exercices créés pendant l'import, réutilisés d'une clé à l'autre */
        private array $created = [],
    ) {
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    /**
     * Ce que dit le fichier pour une clé, sans rien créer.
     *
     * @return array{status: 'mapped'|'ignored'|'unresolved'|'create', target: string|null}
     */
    public function inspect(string $key): array
    {
        if (!\array_key_exists($key, $this->entries)) {
            return ['status' => 'unresolved', 'target' => null];
        }

        $value = $this->entries[$key];

        if (null === $value) {
            return ['status' => 'ignored', 'target' => null];
        }

        if (\is_array($value)) {
            return ['status' => 'create', 'target' => null];
        }

        if (self::UNRESOLVED === trim($value)) {
            return ['status' => 'unresolved', 'target' => null];
        }

        return ['status' => 'mapped', 'target' => $value];
    }

    /**
     * L'exercice Kadens d'une clé, ou `null` si elle est volontairement ignorée.
     *
     * Une entrée `{"create": …}` est matérialisée ici, **une seule fois** par
     * nom : deux clés qui créent le même exercice le partagent, sinon un
     * unilatéral et son bilatéral pourraient se retrouver dupliqués sous le même
     * nom dans la bibliothèque.
     *
     * @throws \RuntimeException si la clé n'est pas tranchée, ou vise un nom absent
     */
    public function resolve(string $key): ?Exercise
    {
        $entry = $this->inspect($key);

        if ('ignored' === $entry['status']) {
            return null;
        }

        if ('unresolved' === $entry['status']) {
            throw new \RuntimeException(\sprintf('Clé non tranchée dans le fichier de correspondance : « %s ».', $key));
        }

        if ('create' === $entry['status']) {
            /** @var array{create: array{name?: string, activity?: string, targetAreas?: list<string>, description?: string}} $value */
            $value = $this->entries[$key];
            $name = trim($value['create']['name'] ?? '');

            if ('' === $name) {
                throw new \RuntimeException(\sprintf('La clé « %s » demande une création sans "name".', $key));
            }

            return $this->create($name, $value['create']);
        }

        $name = (string) $entry['target'];

        if (!isset($this->library[$name])) {
            throw new \RuntimeException(\sprintf(
                'La clé « %s » vise « %s », qui n\'existe pas dans la bibliothèque de %s.',
                $key,
                $name,
                (string) $this->owner->getEmail(),
            ));
        }

        return $this->library[$name];
    }

    /**
     * Un exercice perso, créé à la volée. Il est **persisté sans flush** : c'est
     * l'appelant qui décide d'écrire, ce qui laisse le dry-run inoffensif.
     *
     * @param array{name?: string, activity?: string, targetAreas?: list<string>, description?: string} $spec
     */
    private function create(string $name, array $spec): Exercise
    {
        if (isset($this->created[$name])) {
            return $this->created[$name];
        }

        if (isset($this->library[$name])) {
            return $this->library[$name];
        }

        $areas = [];

        foreach ($spec['targetAreas'] ?? [] as $area) {
            $parsed = TargetArea::tryFrom($area);

            if (null === $parsed) {
                throw new \RuntimeException(\sprintf('Zone inconnue « %s » pour l\'exercice « %s ».', $area, $name));
            }

            $areas[] = $parsed;
        }

        $exercise = (new Exercise())
            ->setName($name)
            ->setOwner($this->owner)
            ->setActivity(ActivityType::tryFrom($spec['activity'] ?? 'gym') ?? ActivityType::GYM)
            ->setDescription($spec['description'] ?? null)
            ->setTargetAreas([] === $areas ? null : $areas);

        $this->em->persist($exercise);
        $this->created[$name] = $exercise;

        return $exercise;
    }

    /** @return list<Exercise> les exercices créés à la volée, dans l'ordre de création */
    public function createdExercises(): array
    {
        return array_values($this->created);
    }

    /**
     * Les noms de la bibliothèque les plus proches d'un libellé Blast, du
     * meilleur au moins bon. Sert au rapport, jamais à l'appariement.
     *
     * Le score croise deux mesures parce qu'aucune ne suffit seule : le
     * recouvrement de mots attrape `Curl marteau` dans `Curl marteau (Hammer
     * curl)`, la distance d'édition attrape les variantes orthographiques
     * (`mollet`/`mollets`) que le découpage en mots rate.
     *
     * @return list<string>
     */
    public function suggestionsFor(string $label, int $limit = 3): array
    {
        $scored = [];

        foreach (array_keys($this->library) as $name) {
            $scored[$name] = self::score($label, $name);
        }

        arsort($scored);

        return \array_slice(array_keys(array_filter($scored, static fn (float $s): bool => $s > 0.15)), 0, $limit);
    }

    private static function score(string $a, string $b): float
    {
        $wordsA = self::words($a);
        $wordsB = self::words($b);

        if ([] === $wordsA || [] === $wordsB) {
            return 0.0;
        }

        $common = \count(array_intersect($wordsA, $wordsB));
        $overlap = $common / max(\count($wordsA), \count($wordsB));

        $normA = implode(' ', $wordsA);
        $normB = implode(' ', $wordsB);
        $distance = levenshtein($normA, $normB);
        $edit = 1 - ($distance / max(\strlen($normA), \strlen($normB)));

        return (0.65 * $overlap) + (0.35 * max(0.0, $edit));
    }

    /**
     * Les mots-outils ne portent aucune information de mouvement et gonfleraient
     * artificiellement le recouvrement entre deux exercices sans rapport
     * (`extension de jambes` / `flexion de poignet`) : `TextNormalizer` les
     * retire, et c'est lui qui porte la règle depuis qu'elle sert aussi à
     * l'import de la bibliothèque et à la recherche des palettes.
     *
     * @return list<string>
     */
    private static function words(string $value): array
    {
        return TextNormalizer::words($value);
    }

    /**
     * Charge le fichier de correspondance et la bibliothèque du propriétaire.
     *
     * La bibliothèque visible est celle de l'utilisateur : la globale plus ses
     * exercices perso (`findLibraryForUser`). Un import ne doit pas pouvoir
     * pointer un exercice qu'il n'a pas le droit de voir.
     *
     * @throws \RuntimeException si le fichier est absent ou mal formé
     */
    public static function load(
        string $file,
        User $owner,
        ExerciseRepository $exercises,
        EntityManagerInterface $em,
    ): self {
        $entries = [];

        if (is_file($file)) {
            $raw = file_get_contents($file);

            if (false === $raw) {
                throw new \RuntimeException(\sprintf('Fichier de correspondance illisible : %s', $file));
            }

            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException(\sprintf('JSON invalide dans %s : %s', basename($file), $e->getMessage()));
            }

            foreach ($decoded as $key => $value) {
                // Les clés de commentaire (`_note`) permettent d'annoter le
                // fichier, que JSON ne laisse pas commenter autrement.
                if (str_starts_with((string) $key, '_')) {
                    continue;
                }

                if (null !== $value && !\is_string($value) && !(\is_array($value) && isset($value['create']) && \is_array($value['create']))) {
                    throw new \RuntimeException(\sprintf('Valeur invalide pour « %s » : attendu une chaîne, null, ou {"create": …}.', $key));
                }

                /** @var string|null|array{create: array{name?: string, activity?: string, targetAreas?: list<string>, description?: string}} $value */
                $entries[(string) $key] = $value;
            }
        }

        $library = [];

        foreach ($exercises->findLibraryForUser($owner) as $exercise) {
            $library[(string) $exercise->getName()] = $exercise;
        }

        return new self($entries, $library, $em, $owner);
    }
}
