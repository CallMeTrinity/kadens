<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\SetType;

/**
 * La lecture des exports CSV de Blast, et **rien d'autre** : ce service ne
 * connaît ni Doctrine, ni la bibliothèque d'exercices, ni le mapping. Il rend
 * une structure plate que `TrainingHistoryImporter` transforme en entités.
 *
 * La séparation n'est pas cosmétique. Le CSV est la partie irrégulière du
 * problème (trois fichiers, deux séparateurs, des lignes qu'on ne sait pas
 * représenter), l'écriture en base est la partie régulière. Les tester ensemble
 * demanderait une base pour vérifier qu'on lit bien une virgule.
 *
 * ## Ce que l'export contient, et ce qu'il faut en savoir
 *
 * **Une ligne = une série**, pas un exercice. Un exercice de 4 séries occupe 4
 * lignes identiques sauf `Reps`/`Charge`. La colonne `Date` porte l'horodatage
 * de la **séance**, pas de la série : elle est répétée à l'identique sur toutes
 * les lignes d'une même séance, ce qui en fait son identifiant naturel.
 *
 * Trois pièges de forme, tous rencontrés dans les fichiers réels :
 *
 * 1. **Le séparateur change d'un export à l'autre** (virgule en 2024/2025,
 *    point-virgule en 2026). Il est détecté sur la ligne d'en-tête, jamais
 *    supposé.
 * 2. **Les champs sont quotés** dès qu'ils contiennent le séparateur
 *    (`"Shoulders, Arms"` comme nom de séance). D'où `fgetcsv`, et pas un
 *    `explode` qui couperait le titre en deux colonnes.
 * 3. **Les colonnes sont adressées par nom**, pas par rang : `csv.headers.steps`
 *    trahit un en-tête non traduit côté Blast, donc un format qui peut bouger.
 *    Un en-tête manquant est une erreur franche, pas une colonne lue de travers.
 *
 * ## Les regroupements, et pourquoi ils sont consécutifs
 *
 * Une séance se découpe en **runs** : des lignes voisines qui décrivent le même
 * exercice. Regrouper par nom sur toute la séance serait plus simple et faux —
 * sur 19 séances des trois fichiers, un exercice réapparaît plus loin (retour à
 * un mouvement, circuit). Le fusionner écraserait l'ordre réel du travail ; un
 * run par passage le préserve, au prix de deux `LoggedExercise` pour un même
 * exercice, ce que le modèle accepte sans réserve.
 *
 * La clé d'un run croise **nom + équipement + exécution**, parce que Kadens n'a
 * pas de champ « équipement » ni « unilatéral » : ce sont des exercices
 * distincts (`CLAUDE.md` §3, « variantes = entrées distinctes »). `Bilatéral`
 * est le défaut et ne rentre pas dans la clé, sinon la quasi-totalité des
 * exercices porterait un suffixe qui ne distingue rien.
 *
 * ## Les fuseaux
 *
 * L'export horodate en **heure locale**, sans décalage. PHP tourne en UTC ici,
 * donc lire `2026-01-02 11:37:07` sans fuseau explicite décalerait chaque
 * séance importée d'une à deux heures par rapport à celles que le téléphone
 * logue (lui envoie de l'UTC, cf. `docs/api-mobile.md`). Le fuseau de lecture
 * est donc un paramètre, et le service rend des instants **en UTC**.
 *
 * Le jour, lui, reste le jour **local** : une séance du 2 janvier au soir est du
 * 2 janvier au calendrier, quelle que soit sa traduction en UTC.
 */
final class BlastCsvParser
{
    /** Fuseau dans lequel Blast a horodaté ses séances. */
    public const string DEFAULT_TIMEZONE = 'Europe/Paris';

    /**
     * Colonnes dont on a besoin. La présence est vérifiée à l'ouverture : mieux
     * vaut refuser un fichier que d'importer 5 000 séries de valeurs nulles.
     */
    private const array REQUIRED = ['Date', 'Entraînement', 'Exercise', 'Equipement', 'Exécution', 'Type de Set', 'Reps', 'Charge', 'Durée'];

    /** Exécution qui ne qualifie pas l'exercice : c'est le cas ordinaire. */
    private const string DEFAULT_EXECUTION = 'Bilatéral';

    /**
     * Les types de série de Blast vers les nôtres. L'export n'a **pas** de notion
     * d'échauffement : tout ce qui est importé compte donc comme volume de
     * travail, et le tonnage historique est légèrement surévalué. Ce n'est pas
     * rattrapable, la donnée n'existe pas.
     */
    private const array SET_TYPES = [
        'Normale' => SetType::NORMAL,
        'Échec' => SetType::TO_FAILURE,
        'Dégressive' => SetType::DEGRESSIVE,
    ];

    /**
     * Les séances d'un export, dans l'ordre chronologique.
     *
     * @return list<array{
     *     sourceKey: string,
     *     title: string,
     *     startedAt: \DateTimeImmutable,
     *     endedAt: \DateTimeImmutable|null,
     *     loggedAt: \DateTimeImmutable,
     *     date: \DateTimeImmutable,
     *     entries: list<array{
     *         key: string,
     *         name: string,
     *         equipment: string,
     *         execution: string,
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
            $sourceKey = trim($row['Date'] ?? '');

            // Une ligne sans exercice ne décrit rien de logable. Il en existe
            // (une séance de fractionné dont l'export n'a gardé que l'en-tête) ;
            // la séance disparaîtra si c'est sa seule ligne, ce qui est correct.
            if ('' === $name || '' === $sourceKey) {
                continue;
            }

            $started = $this->instant($sourceKey, $tz);

            if (null === $started) {
                throw new \RuntimeException(\sprintf('Horodatage illisible dans %s : « %s ».', basename($file), $sourceKey));
            }

            $sessions[$sourceKey] ??= [
                'sourceKey' => $sourceKey,
                'title' => trim($row['Entraînement'] ?? '') ?: 'Séance importée',
                'startedAt' => $started,
                'endedAt' => $this->endOf($started, $row["Durée de l'Entraînement"] ?? null),
                // Blast horodate ses séances : l'instant dont ses séries héritent
                // est le vrai départ, pas une convention (cf. `FitNotesCsvParser`).
                'loggedAt' => $started,
                // Le jour tel qu'il a été vécu, donc lu dans le fuseau local
                // avant que `$started` ne bascule en UTC.
                'date' => new \DateTimeImmutable(substr($sourceKey, 0, 10), $tz),
                'entries' => [],
            ];

            $entry = $this->entryOf($row, $name);
            $entries = &$sessions[$sourceKey]['entries'];
            $last = array_key_last($entries);

            // Un run, c'est la continuité : même clé que la ligne précédente. Le
            // même exercice croisé plus loin ouvre une seconde entrée.
            if (null === $last || $entries[$last]['key'] !== $entry['key']) {
                $entries[] = $entry;
                $last = array_key_last($entries);
            }

            $entries[$last]['sets'][] = $this->setOf($row);
            unset($entries);
        }

        $sessions = array_values($sessions);
        usort($sessions, static fn (array $a, array $b): int => $a['startedAt'] <=> $b['startedAt']);

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

                // Une ligne plus courte que l'en-tête casserait `array_combine`.
                // On la complète plutôt que de la refuser : une colonne absente
                // se lit comme vide, ce qu'elle est.
                $cells = array_pad(array_slice($cells, 0, $width), $width, '');

                /** @var array<string, string> $row */
                $row = array_combine($headers, array_map(static fn ($c): string => trim((string) $c), $cells));

                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * L'entrée d'exercice décrite par une ligne, sans ses séries.
     *
     * @param array<string, string> $row
     *
     * @return array{key: string, name: string, equipment: string, execution: string, sets: list<array{setType: SetType, reps: int|null, weightKg: float|null, durationSeconds: int|null}>}
     */
    private function entryOf(array $row, string $name): array
    {
        $equipment = trim($row['Equipement'] ?? '');
        $execution = trim($row['Exécution'] ?? '') ?: self::DEFAULT_EXECUTION;

        return [
            'key' => self::key($name, $equipment, $execution),
            'name' => $name,
            'equipment' => $equipment,
            'execution' => $execution,
            'sets' => [],
        ];
    }

    /**
     * La clé de mapping d'un exercice : ce qui, dans l'export, désigne une
     * entrée distincte de la bibliothèque Kadens. Publique parce que le fichier
     * de correspondance est écrit à la main et doit pouvoir être régénéré à
     * l'identique.
     */
    public static function key(string $name, string $equipment, string $execution): string
    {
        $key = $name . '|' . $equipment;

        return self::DEFAULT_EXECUTION === $execution ? $key : $key . '|' . $execution;
    }

    /**
     * La série décrite par une ligne.
     *
     * Un zéro de Blast est une **absence**, pas une valeur : c'est ce qu'il écrit
     * pour une charge au poids du corps comme pour une durée qu'il n'a pas
     * exportée. Le convertir en `null` évite qu'un 0 kg n'entre dans les records
     * (`getTopWeightKg`) et que `getTonnageKg` ne compte une série fantôme.
     *
     * Une série sans reps **ni** durée est conservée quand même : ce sont les
     * isométries (planche, suspension), dont Blast n'exporte pas le temps. La
     * série a bien eu lieu, seule sa mesure manque — la jeter perdrait le fait.
     *
     * @param array<string, string> $row
     *
     * @return array{setType: SetType, reps: int|null, weightKg: float|null, durationSeconds: int|null}
     */
    private function setOf(array $row): array
    {
        return [
            'setType' => self::SET_TYPES[trim($row['Type de Set'] ?? '')] ?? SetType::NORMAL,
            'reps' => $this->positiveInt($row['Reps'] ?? null),
            'weightKg' => $this->positiveFloat($row['Charge'] ?? null),
            'durationSeconds' => $this->positiveInt($row['Durée'] ?? null),
        ];
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

    /** L'instant d'une séance, lu en heure locale et rendu en UTC. */
    private function instant(string $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, $tz);

        return false === $parsed ? null : $parsed->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * La fin d'une séance, déduite de sa durée `HH:MM:SS`.
     *
     * `00:00:00` n'est pas une séance instantanée, c'est une durée que Blast n'a
     * pas mesurée (50 lignes des trois exports). On rend `null` plutôt qu'un
     * `endedAt` égal au départ, qui afficherait une séance de zéro minute.
     */
    private function endOf(\DateTimeImmutable $started, ?string $duration): ?\DateTimeImmutable
    {
        if (null === $duration || 1 !== preg_match('/^(\d+):([0-5]\d):([0-5]\d)$/', trim($duration), $m)) {
            return null;
        }

        $seconds = ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3];

        return $seconds > 0 ? $started->modify(\sprintf('+%d seconds', $seconds)) : null;
    }
}
