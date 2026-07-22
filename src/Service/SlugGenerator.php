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

        $slug = $base;
        $suffix = 2;

        while (null !== $repository->findOneBy([$field => $slug])) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
