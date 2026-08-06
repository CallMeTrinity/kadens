<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\SetType;

/**
 * La lecture des exports CSV de FitNotes, et **rien d'autre** — même partage des
 * rôles que `BlastCsvParser` : ce service ne connaît ni Doctrine, ni la
 * bibliothèque d'exercices, ni le mapping. Il rend la structure plate que
 * `TrainingHistoryImporter` transforme en entités, exactement la même que Blast.
 *
 * C'est ce qui rend l'ajout d'une source bon marché : le format est la partie
 * irrégulière du problème, l'écriture en base la partie régulière, et seule la
 * première change d'une application à l'autre.
 *
 * ## Ce que l'export contient, et ce qu'il faut en savoir
 *
 * **Une ligne = une série**, comme chez Blast. Trois différences comptent, et
 * elles ne sont pas cosmétiques :
 *
 * 1. **La date est un jour, pas un instant** (`2025-09-24`). Il n'y a donc ni
 *    heure de départ, ni durée de séance : le jour est l'identifiant de la
 *    séance, et une journée d'entraînement se lit comme **une seule** séance.
 * 2. **Pas de nom de séance**, pas d'équipement, pas d'exécution, pas de type de
 *    série. La clé de mapping se réduit donc au **nom de l'exercice** (elle
 *    croisait nom + équipement + exécution chez Blast), et toutes les séries
 *    entrent en `SetType::NORMAL` — la notion d'échec ou de dégressive n'existe
 *    pas dans le fichier, seulement parfois dans un commentaire en français.
 * 3. **`Comment` est la dernière colonne et n'est pas quotée**, alors qu'elle
 *    contient des virgules (« Attention position (pas instinctive encore), léger
 *    tiraillement… »). Une telle ligne compte plus de champs que l'en-tête : le
 *    surplus est **recollé** dans la dernière colonne au lieu d'être jeté, sans
 *    quoi un commentaire sur deux serait tronqué à sa première virgule.
 *
 * ## Le titre, qui est dérivé et pas inventé
 *
 * `Category` (`Legs`, `Back`, `Abs`…) qualifie l'exercice, pas la séance ; c'est
 * pourtant la seule information de structure du fichier. Le titre d'une séance
 * est donc la liste de ses catégories **distinctes, dans l'ordre d'apparition**
 * (« Back, Legs, Chest »). Ce n'est pas le nom que l'utilisateur aurait donné,
 * mais c'est lisible au calendrier et entièrement déduit de la donnée — là où un
 * « Séance importée » répété 106 fois n'aurait rien distingué.
 *
 * ## Ce que le modèle ne sait pas tenir, et qui part en note
 *
 * `Distance` (les ports de charge : 30 m de Farmer's Carry) et `Comment` n'ont
 * aucune colonne en face dans `LoggedSet`. Les jeter perdrait un fait et un
 * ressenti écrits à la main ; on les recopie donc en toutes lettres dans
 * `LoggedExercise.notes`, où ils s'affichent (`components/_log_exrow.html.twig`).
 * Non exploitable statistiquement, mais lisible — cf. l'en-tête de
 * `TrainingHistoryImporter`. La série, elle, est **conservée** : elle a eu lieu,
 * seule sa mesure manque, comme les isométries sans temps chez Blast.
 *
 * ## Les fuseaux
 *
 * Le fichier ne porte que des jours, il n'y a donc rien à convertir. Le fuseau
 * sert à deux choses seulement : lire le jour tel qu'il a été vécu, et fabriquer
 * l'instant unique dont les séries héritent (`loggedAt`). Cet instant est
 * **midi local**, pas minuit : minuit local bascule la veille en UTC, et
 * `LogMetrics::loggedAt()` daterait alors la séance du mauvais jour.
 */
final class FitNotesCsvParser
{
    /** Fuseau dans lequel FitNotes a daté ses séances. */
    public const string DEFAULT_TIMEZONE = 'Europe/Paris';

    /**
     * Colonnes dont on a besoin. Même garde-fou que chez Blast : mieux vaut
     * refuser un fichier que d'importer 1 700 séries de valeurs nulles.
     */
    private const array REQUIRED = ['Date', 'Exercise', 'Category', 'Weight', 'Weight Unit', 'Reps', 'Distance', 'Distance Unit', 'Time', 'Comment'];

    /**
     * Préfixe de la clé source. Le namespace des uuid dérivés est commun à toutes
     * les sources (cf. `TrainingHistoryImporter`) : sans lui, une séance FitNotes
     * du 2 janvier et une séance Blast identifiée par le même texte tomberaient
     * sur le même uuid, et le second import effacerait le premier.
     */
    private const string SOURCE = 'fitnotes';

    /**
     * Les séances d'un export, dans l'ordre chronologique.
     *
     * @return list<array{
     *     sourceKey: string,
     *     title: string,
     *     startedAt: null,
     *     endedAt: null,
     *     loggedAt: \DateTimeImmutable,
     *     date: \DateTimeImmutable,
     *     entries: list<array{
     *         key: string,
     *         name: string,
     *         category: string,
     *         notes: string|null,
     *         sets: list<array{setType: SetType, reps: int|null, weightKg: float|null, durationSeconds: int|null}>
     *     }>
     * }>
     *
     * @throws \RuntimeException si le fichier est illisible ou mal formé
     */
    public function parse(string $file, string $timezone = self::DEFAULT_TIMEZONE): array
    {
        $tz = new \DateTimeZone($timezone);
        $sessions = [];

        foreach ($this->rows($file) as $row) {
            $name = trim($row['Exercise'] ?? '');
            $day = trim($row['Date'] ?? '');

            // Une ligne sans exercice ne décrit rien de logable.
            if ('' === $name || '' === $day) {
                continue;
            }

            $date = $this->day($day, $tz);

            if (null === $date) {
                throw new \RuntimeException(\sprintf('Date illisible dans %s : « %s ».', basename($file), $day));
            }

            $sessions[$day] ??= [
                'sourceKey' => self::SOURCE . '|' . $day,
                // Complété au fil des lignes : le titre n'est connu qu'une fois
                // toutes les catégories de la journée vues.
                'title' => '',
                // FitNotes n'exporte aucune heure. Une borne inventée ferait
                // afficher une durée de séance à `LogMetrics::durationSeconds()`.
                'startedAt' => null,
                'endedAt' => null,
                'loggedAt' => $date->setTime(12, 0)->setTimezone(new \DateTimeZone('UTC')),
                'date' => $date,
                'entries' => [],
            ];

            $entry = $this->entryOf($row, $name);
            $entries = &$sessions[$day]['entries'];
            $last = array_key_last($entries);

            // Un run, c'est la continuité : même exercice que la ligne
            // précédente. Le même exercice croisé plus loin (retour à un
            // mouvement, circuit) ouvre une seconde entrée, ce qui préserve
            // l'ordre réel du travail.
            if (null === $last || $entries[$last]['key'] !== $entry['key']) {
                $entries[] = $entry;
                $last = array_key_last($entries);
            }

            $entries[$last]['sets'][] = $this->setOf($row, basename($file));
            $entries[$last]['notes'] = $this->appendNote(
                $entries[$last]['notes'],
                $this->noteOf($row),
            );
            unset($entries);
        }

        foreach ($sessions as $day => $session) {
            $sessions[$day]['title'] = $this->titleOf($session['entries']);
        }

        $sessions = array_values($sessions);
        usort($sessions, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $sessions;
    }

    /**
     * Les lignes du fichier, en tableaux associatifs.
     *
     * @return iterable<array<string, string>>
     */
    private function rows(string $file): iterable
    {
        if (!is_readable($file)) {
            throw new \RuntimeException(\sprintf('Fichier illisible : %s', $file));
        }

        $handle = fopen($file, 'r');

        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Impossible d\'ouvrir %s', $file));
        }

        try {
            $first = fgets($handle);

            if (false === $first) {
                throw new \RuntimeException(\sprintf('Fichier vide : %s', basename($file)));
            }

            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

            // Le BOM, s'il y en a un, collerait au premier en-tête (`\u{FEFF}Date`)
            // et rendrait la colonne `Date` introuvable.
            $headers = str_getcsv(ltrim($first, "\u{FEFF}"), $delimiter, '"', '');
            $headers = array_map(static fn (?string $h): string => trim((string) $h), $headers);

            $missing = array_diff(self::REQUIRED, $headers);

            if ([] !== $missing) {
                throw new \RuntimeException(\sprintf(
                    'Colonnes absentes de %s : %s.',
                    basename($file),
                    implode(', ', $missing),
                ));
            }

            $width = \count($headers);

            while (false !== ($cells = fgetcsv($handle, 0, $delimiter, '"', ''))) {
                // Ligne vide en fin de fichier : `fgetcsv` rend `[null]`.
                if ([null] === $cells || [] === $cells) {
                    continue;
                }

                $cells = array_map(static fn ($c): string => (string) $c, $cells);

                // `Comment` est la dernière colonne, n'est pas quotée, et contient
                // des virgules : le surplus lui appartient, il se recolle. Le
                // tronquer (ce que fait le parseur Blast, dont la dernière colonne
                // est une durée) couperait un commentaire sur deux.
                if (\count($cells) > $width) {
                    $overflow = \array_slice($cells, $width - 1);
                    $cells = \array_slice($cells, 0, $width - 1);
                    $cells[] = implode($delimiter, $overflow);
                }

                // Une ligne plus courte que l'en-tête casserait `array_combine`.
                $cells = array_pad($cells, $width, '');

                /** @var array<string, string> $row */
                $row = array_combine($headers, array_map(trim(...), $cells));

                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * L'entrée d'exercice décrite par une ligne, sans ses séries ni ses notes.
     *
     * @param array<string, string> $row
     *
     * @return array{key: string, name: string, category: string, notes: string|null, sets: list<array{setType: SetType, reps: int|null, weightKg: float|null, durationSeconds: int|null}>}
     */
    private function entryOf(array $row, string $name): array
    {
        return [
            'key' => self::key($name),
            'name' => $name,
            'category' => trim($row['Category'] ?? ''),
            'notes' => null,
            'sets' => [],
        ];
    }

    /**
     * La clé de mapping d'un exercice. FitNotes ne décrit ni l'équipement ni
     * l'exécution — c'est donc le nom seul, et il suffit : sur l'export réel,
     * chaque nom porte une et une seule `Category`, il n'y a rien à désambiguïser.
     *
     * Publique pour la même raison que chez Blast : le fichier de correspondance
     * est écrit à la main et doit pouvoir être régénéré à l'identique.
     */
    public static function key(string $name): string
    {
        return trim($name);
    }

    /**
     * Le titre d'une séance : ses catégories distinctes, dans l'ordre
     * d'apparition. Voir l'en-tête de classe.
     *
     * @param list<array{category: string, ...}> $entries
     */
    private function titleOf(array $entries): string
    {
        $categories = [];

        foreach ($entries as $entry) {
            if ('' !== $entry['category'] && !\in_array($entry['category'], $categories, true)) {
                $categories[] = $entry['category'];
            }
        }

        return [] === $categories ? 'Séance importée' : implode(', ', $categories);
    }

    /**
     * La série décrite par une ligne.
     *
     * Un zéro de FitNotes est une **absence**, pas une valeur, exactement comme
     * chez Blast : `0.0 kgs` est le poids du corps, `0:00:00` une durée non
     * mesurée, `0.0 m` une distance qui ne s'applique pas.
     *
     * Une série sans reps **ni** durée est conservée quand même — un port de
     * charge (`Farmer's Carry`, 30 m) n'a ni l'un ni l'autre, la série a bien eu
     * lieu et sa distance part en note.
     *
     * @param array<string, string> $row
     *
     * @return array{setType: SetType, reps: int|null, weightKg: float|null, durationSeconds: int|null}
     */
    private function setOf(array $row, string $file): array
    {
        return [
            // FitNotes n'a pas la notion : ni échauffement, ni échec, ni
            // dégressive. Tout compte comme volume de travail, le tonnage
            // historique est donc un peu surévalué. Non rattrapable.
            'setType' => SetType::NORMAL,
            'reps' => $this->positiveInt($row['Reps'] ?? null),
            'weightKg' => $this->weightKg($row, $file),
            'durationSeconds' => $this->seconds($row['Time'] ?? null),
        ];
    }

    /**
     * La charge d'une série, **en kilos** (`CLAUDE.md` §3, unités normalisées).
     *
     * FitNotes se règle en livres, et l'export le dit dans `Weight Unit`. Une
     * unité inconnue est une erreur franche : convertir de travers passerait
     * inaperçu et fausserait tonnage et records pour toujours.
     *
     * @param array<string, string> $row
     */
    private function weightKg(array $row, string $file): ?float
    {
        $weight = $this->positiveFloat($row['Weight'] ?? null);

        if (null === $weight) {
            return null;
        }

        $unit = mb_strtolower(trim($row['Weight Unit'] ?? ''));

        return match ($unit) {
            'kg', 'kgs', '' => $weight,
            'lb', 'lbs' => round($weight * 0.45359237, 2),
            default => throw new \RuntimeException(\sprintf('Unité de charge inconnue dans %s : « %s ».', $file, $unit)),
        };
    }

    /**
     * La note d'une ligne : sa distance et son commentaire, en toutes lettres.
     *
     * La distance est rendue **verbatim avec son unité** (« 30 m »), sans
     * conversion : ce n'est pas une valeur exploitée, c'est du texte, et une
     * unité recopiée ne peut pas se tromper.
     *
     * @param array<string, string> $row
     */
    private function noteOf(array $row): ?string
    {
        $parts = [];
        $distance = $this->positiveFloat($row['Distance'] ?? null);

        if (null !== $distance) {
            $unit = trim($row['Distance Unit'] ?? '');
            $rendered = rtrim(rtrim(number_format($distance, 2, ',', ' '), '0'), ',');
            $parts[] = trim(\sprintf('Distance : %s %s', $rendered, $unit));
        }

        $comment = trim($row['Comment'] ?? '');

        if ('' !== $comment) {
            $parts[] = $comment;
        }

        return [] === $parts ? null : implode("\n", $parts);
    }

    /**
     * Empile une note sur celles déjà vues pour la même entrée, **sans répéter**.
     *
     * Les lignes d'un même exercice répètent souvent la même distance série après
     * série (trois fois « Distance : 30 m ») : les accumuler donnerait une note
     * illisible qui n'ajoute rien. Un commentaire réellement différent d'une série
     * à l'autre, lui, est conservé sur sa propre ligne.
     */
    private function appendNote(?string $existing, ?string $addition): ?string
    {
        if (null === $addition) {
            return $existing;
        }

        if (null === $existing) {
            return $addition;
        }

        $lines = explode("\n", $existing);

        foreach (explode("\n", $addition) as $line) {
            if (!\in_array($line, $lines, true)) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    private function positiveInt(?string $raw): ?int
    {
        $value = $this->positiveFloat($raw);

        return null === $value ? null : (int) round($value);
    }

    private function positiveFloat(?string $raw): ?float
    {
        $raw = trim((string) $raw);

        if ('' === $raw || !is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        return $value > 0.0 ? $value : null;
    }

    /** Le jour d'une séance, lu dans le fuseau local, à minuit. */
    private function day(string $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $tz);

        return false === $parsed ? null : $parsed;
    }

    /**
     * La durée d'une série, `H:MM:SS`. `0:00:00` n'est pas une série
     * instantanée, c'est une durée que FitNotes n'a pas mesurée.
     */
    private function seconds(?string $raw): ?int
    {
        if (null === $raw || 1 !== preg_match('/^(\d+):([0-5]\d):([0-5]\d)$/', trim($raw), $m)) {
            return null;
        }

        $seconds = ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3];

        return $seconds > 0 ? $seconds : null;
    }
}
