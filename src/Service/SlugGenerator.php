<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Génère des slugs uniques pour n'importe quelle entité disposant d'un champ
 * slug (Workout aujourd'hui, PlanTemplate en Phase 5). En cas de collision, on
 * suffixe par un compteur (`titre`, `titre-2`, `titre-3`, ...).
 */
final class SlugGenerator
{
    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param class-string $entityClass entité cible (pour vérifier l'unicité)
     * @param string        $field       propriété portant le slug
     */
    public function generate(string $text, string $entityClass, string $field = 'slug'): string
    {
        $base = strtolower($this->slugger->slug($text)->toString());

        if ('' === $base) {
            $base = 'sans-titre';
        }

        $repository = $this->entityManager->getRepository($entityClass);

        // Slugs déjà générés dans la transaction courante mais pas encore flushés :
        // sans ça, cloner plusieurs séances de même titre d'un coup (duplication de
        // plan / de semaine) leur donnerait le même slug -> collision au flush.
        $pending = $this->pendingSlugs($entityClass, $field);

        $slug = $base;
        $suffix = 2;

        while (\in_array($slug, $pending, true) || null !== $repository->findOneBy([$field => $slug])) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * Slugs portés par les entités du même type déjà persistées (donc programmées
     * pour insertion) mais pas encore écrites en base.
     *
     * @param class-string $entityClass
     *
     * @return list<string>
     */
    private function pendingSlugs(string $entityClass, string $field): array
    {
        $getter = 'get'.ucfirst($field);
        $slugs = [];

        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof $entityClass && method_exists($entity, $getter)) {
                $value = $entity->{$getter}();
                if (null !== $value) {
                    $slugs[] = (string) $value;
                }
            }
        }

        return $slugs;
    }
}
