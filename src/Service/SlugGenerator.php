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
     * Racine du slug, sans suffixe d'unicité. Exposée pour reconnaître un slug
     * encore issu d'un titre donné (ex. « nouvelle-seance », « nouvelle-seance-4 »
     * pour un brouillon jamais renommé), cf. `derivesFrom()`.
     */
    public function base(string $text): string
    {
        $base = strtolower($this->slugger->slug($text)->toString());

        return '' === $base ? 'sans-titre' : $base;
    }

    /**
     * Le slug dérive-t-il encore de ce texte, c'est-à-dire sa racine suivie au plus
     * d'un suffixe numérique d'unicité ? Sert à ne régénérer le slug d'un brouillon
     * qu'au premier vrai renommage, sans jamais toucher celui d'une entité déjà
     * nommée (ses URLs de partage public doivent rester stables).
     */
    public function derivesFrom(?string $slug, string $text): bool
    {
        if (null === $slug) {
            return true;
        }

        return 1 === preg_match('/^'.preg_quote($this->base($text), '/').'(-\d+)?$/', $slug);
    }

    /**
     * @param class-string $entityClass entité cible (pour vérifier l'unicité)
     * @param string        $field       propriété portant le slug
     */
    public function generate(string $text, string $entityClass, string $field = 'slug'): string
    {
        $base = $this->base($text);

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
