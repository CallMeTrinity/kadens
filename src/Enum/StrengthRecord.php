<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Les records de force de la fiche athlète, et **l'exercice qui les alimente**.
 *
 * Un record déclaré à la main vieillit : on le saisit une fois, on le bat en
 * séance, et la fiche continue d'afficher l'ancien. Chaque case porte donc les
 * `refKey` de la bibliothèque globale dont le réalisé la met à jour — c'est le
 * seul lien entre « ce que j'annonce » et « ce que j'ai fait ».
 *
 * Deux règles tiennent cette liste :
 *
 * 1. **`refKey`, jamais un nom.** La clé est l'identité stable d'une entrée de
 *    la globale (cf. `Exercise::$refKey`) : renommer l'exercice ne détache pas
 *    le record. Un exercice perso n'en porte pas, donc n'alimente jamais une
 *    case — c'est voulu, un record de squat ne se lit pas sur « Squat maison ».
 * 2. **Une case = LE mouvement, pas sa famille.** Le squat avant, le développé
 *    incliné, le dips à la machine ou la pompe diamant sont d'autres exercices,
 *    pas des efforts comparables. Deux cases seulement portent plusieurs clés,
 *    et pour la même raison — un lift exécuté autrement, pas un lift voisin :
 *    le soulevé de terre (conventionnel et sumo) et la traction (prise normale
 *    et prise large, toutes deux en pronation stricte). La supination
 *    (chin-up), l'explosive, l'assistée et l'australienne restent dehors : ce
 *    sont d'autres difficultés, pas d'autres prises.
 *
 * Ce que chaque case mesure est porté ici aussi (`metric()`) : une charge, des
 * répétitions au poids du corps, ou un temps. C'est ce qui décide de la lecture
 * du réalisé dans `AthleteRecords`, et pourquoi la traction a deux cases —
 * lestée on lit des kilos, à vide on compte des répétitions.
 */
enum StrengthRecord: string
{
    case SQUAT = 'squat';
    case BENCH = 'bench';
    case DEADLIFT = 'deadlift';
    case OHP = 'ohp';
    case WEIGHTED_PULLUP = 'weighted_pullup';
    case PULLUPS = 'pullups';
    case PUSHUPS = 'pushups';
    case DIPS = 'dips';
    case DEADHANG = 'deadhang';

    /**
     * Le libellé de la LIGNE, pas celui de l'exercice. Il nomme le record
     * (« Squat »), là où la bibliothèque nomme un mouvement (« Squat à la
     * barre ») et suit la préférence de langue du compte — deux choses.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::SQUAT => 'Squat',
            self::BENCH => 'Développé couché',
            self::DEADLIFT => 'Soulevé de terre',
            self::OHP => 'Développé militaire',
            self::WEIGHTED_PULLUP => 'Traction lestée',
            self::PULLUPS => 'Tractions',
            self::PUSHUPS => 'Pompes',
            self::DIPS => 'Dips',
            self::DEADHANG => 'Suspension à la barre',
        };
    }

    /**
     * Les entrées de la bibliothèque globale dont le réalisé alimente la case.
     *
     * @return list<string>
     */
    public function refKeys(): array
    {
        return match ($this) {
            self::SQUAT => ['barbell-squat'],
            self::BENCH => ['developpe-couche'],
            self::DEADLIFT => ['souleve-de-terre-traditionnel', 'souleve-de-terre-sumo'],
            self::OHP => ['developpe-militaire'],
            self::WEIGHTED_PULLUP => ['traction-en-pronation-prise-large-lestee'],
            self::PULLUPS => ['traction', 'traction-en-pronation-prise-large'],
            self::PUSHUPS => ['pompe'],
            self::DIPS => ['dips'],
            self::DEADHANG => ['suspension-a-la-barre-fixe'],
        };
    }

    /**
     * Ce que la case mesure. Un gainage n'a pas de charge et une pompe n'en a
     * pas non plus : les lire sur `weightKg` les laisserait vides à jamais.
     */
    public function metric(): RecordMetric
    {
        return match ($this) {
            self::SQUAT, self::BENCH, self::DEADLIFT, self::OHP, self::WEIGHTED_PULLUP => RecordMetric::LOAD,
            self::PULLUPS, self::PUSHUPS, self::DIPS => RecordMetric::REPS,
            self::DEADHANG => RecordMetric::HOLD,
        };
    }

    /**
     * Les trois lifts du total SBD, dans l'ordre où ils s'additionnent.
     *
     * @return list<self>
     */
    public static function sbd(): array
    {
        return [self::SQUAT, self::BENCH, self::DEADLIFT];
    }
}
