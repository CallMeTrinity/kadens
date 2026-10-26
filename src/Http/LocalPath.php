<?php

declare(strict_types=1);

namespace App\Http;

/**
 * La page où revenir après un formulaire posé par-dessus une autre page
 * (invitation à choisir un pseudo, message programmé), réduite à un chemin
 * local. Le champ vient du client : sans ce filtre, ce serait une redirection
 * ouverte. Refusés, tout ce qui ne commence pas par un seul `/` (`//hote` et
 * `/\hote` partent ailleurs) et les caractères de contrôle — un navigateur
 * retire tabulations et retours à la ligne d'une URL, `/<TAB>/hote` deviendrait
 * `//hote`.
 */
final class LocalPath
{
    public static function orRoot(string $path): string
    {
        return 1 === preg_match('#^/(?![/\\\\])[^\x00-\x20\x7F]*$#', $path) ? $path : '/';
    }
}
