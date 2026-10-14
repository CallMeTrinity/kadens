<?php

namespace App\Tests;

/**
 * Relire une entité qu'un test vient d'écrire.
 *
 * Dans un test, une entité absente n'est pas un cas à traiter mais un échec :
 * le dire une fois ici évite de déplier chaque relecture en trois lignes
 * (`find`, `assertNotNull`, puis l'appel), et donne à l'analyse statique le
 * type exact plutôt qu'un nullable.
 *
 * Suppose une propriété `$em` (le gestionnaire du test), comme partout ici.
 */
trait FindsEntities
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function mustFind(string $class, mixed $id): object
    {
        $entity = $this->em->find($class, $id);
        self::assertNotNull($entity, \sprintf('%s introuvable en base.', $class));

        return $entity;
    }
}
