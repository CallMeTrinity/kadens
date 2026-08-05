<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\Uid\Uuid;

/**
 * Le type Doctrine `uuid` de symfony/uid, mais stocké en **CHAR(36)** plutôt
 * qu'en BINARY(16).
 *
 * Pourquoi le redéfinir : le type livré par le pont Doctrine choisit sa colonne
 * selon la plateforme, et sur MySQL/MariaDB il retombe systématiquement sur
 * BINARY(16) (`getGuidTypeDeclarationSQL` y vaut déjà CHAR(36), donc sa détection
 * de « type GUID natif » est fausse). Le gain de place du binaire ne compense pas
 * l'illisibilité en debug sur un projet de cette taille : un uuid de séance datée
 * se lit dans un `SELECT`, se recopie dans une URL d'API, se compare à l'œil avec
 * ce que le mobile a envoyé.
 *
 * Il est enregistré **sous le nom `uuid`** (cf. config/packages/doctrine.yaml) :
 * les entités déclarent `#[ORM\Column(type: 'uuid')]` comme d'habitude, et la
 * convention de stockage est la même partout. Côté PHP, la valeur reste un
 * `Symfony\Component\Uid\Uuid`.
 */
final class UuidCharType extends Type
{
    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL(['fixed' => true, 'length' => 36]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Uuid
    {
        if ($value instanceof Uuid || null === $value) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, Uuid::class, ['null', 'string', Uuid::class]);
        }

        try {
            return Uuid::fromString($value);
        } catch (\InvalidArgumentException $e) {
            throw ValueNotConvertible::new($value, Uuid::class, null, $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }

        if (null === $value || '' === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, Uuid::class, ['null', 'string', Uuid::class]);
        }

        try {
            return Uuid::fromString($value)->toRfc4122();
        } catch (\InvalidArgumentException $e) {
            throw ValueNotConvertible::new($value, Uuid::class, null, $e);
        }
    }
}
