<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Le côté d'une planche anatomique.
 *
 * Les deux se dessinent **toujours ensemble, côte à côte**, jamais derrière un
 * onglet : un dos qu'on ne regarde pas est un dos qui n'existe pas, et une séance
 * de tirage paraîtrait n'avoir rien travaillé. L'enum sert donc à indexer les
 * tracés, pas à offrir un choix à l'écran.
 */
enum BodySide: string
{
    case FRONT = 'front';
    case BACK = 'back';
}
