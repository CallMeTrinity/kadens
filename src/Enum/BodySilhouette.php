<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * La silhouette dessinée par la carte musculaire : un choix d'AFFICHAGE, pas une
 * donnée d'identité.
 *
 * **À ne pas confondre avec `Sex`**, et à ne surtout pas dériver de lui à la
 * volée. `Sex` vit dans la fiche athlète, il est nullable, il accepte `OTHER` —
 * qui ne désigne aucun dessin — et il sert au calcul du score de force normalisé
 * (DOTS). Le brancher ici ferait qu'un réglage de rendu toucherait une donnée de
 * calcul : deux questions différentes, deux champs différents. Même décision que
 * `preference.silhouette` côté mobile.
 *
 * Deux cas et pas trois : il n'existe que deux jeux de tracés (cf.
 * `data/body-plates.php`). `Sex` ne sert qu'à choisir la valeur INITIALE, une
 * fois, à la migration.
 */
enum BodySilhouette: string
{
    case MALE = 'male';
    case FEMALE = 'female';

    public function getLabel(): string
    {
        return match ($this) {
            self::MALE => 'Homme',
            self::FEMALE => 'Femme',
        };
    }
}
