<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * À qui s'adresse un message programmé. Un choix explicite plutôt qu'une liste
 * de destinataires « vide = tout le monde » : oublier de cocher quelqu'un ne
 * doit pas envoyer un message d'anniversaire à tous les comptes.
 */
enum MessageAudience: string
{
    case ALL = 'all';
    case USERS = 'users';

    public function getLabel(): string
    {
        return match ($this) {
            self::ALL => 'Tout le monde',
            self::USERS => 'Certains utilisateurs',
        };
    }
}
