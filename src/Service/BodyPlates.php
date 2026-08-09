<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\BodySide;
use App\Enum\BodySilhouette;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * L'accès aux tracés des silhouettes (`data/body-plates.php`).
 *
 * Le service ne décide de rien : il ne sait ni ce qu'est une zone travaillée, ni
 * ce qu'est une charge. C'est la frontière du jumeau mobile — décider de ce qui
 * est peint est du domaine (`BodyLoad`), porter le dessin est du rendu.
 *
 * Le fichier de données pèse ~140 Ko. Il est chargé une seule fois par requête,
 * paresseusement : une page qui n'affiche pas de silhouette ne le lit pas. Sous
 * OPcache, le `require` d'un tableau littéral se résout sans reparser le fichier.
 *
 * @phpstan-type BodyShape array{slug: string, paths: list<string>}
 * @phpstan-type BodyPlate array{viewBox: string, outline: string, muscles: list<BodyShape>, inert: list<BodyShape>}
 */
final class BodyPlates
{
    /**
     * Les slugs inertes, dans l'ordre où ils doivent être dessinés — cf. l'en-tête
     * du fichier de données. Exposé pour le test de cohérence, qui vérifie que la
     * chevelure reste dessinée en dernier.
     */
    public const INERT_ORDER = ['neck', 'hands', 'feet', 'head', 'hair'];

    /** @var array<string, BodyPlate>|null */
    private ?array $plates = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Une planche : un corps, vu d'un côté.
     *
     * @return BodyPlate
     */
    public function plate(BodySilhouette $silhouette, BodySide $side): array
    {
        $plates = $this->all();
        $key = $silhouette->value.'-'.$side->value;

        // Les quatre planches sont garanties par le fichier de données ET par le
        // test de cohérence : une clé absente serait un fichier tronqué, pas un cas
        // à traiter en silence.
        if (!isset($plates[$key])) {
            throw new \LogicException(sprintf('Planche anatomique inconnue : "%s".', $key));
        }

        return $plates[$key];
    }

    /**
     * Toutes les planches, indexées `silhouette-côté`. Sert au test de cohérence,
     * qui doit vérifier les quatre d'un coup.
     *
     * @return array<string, BodyPlate>
     */
    public function all(): array
    {
        return $this->plates ??= require $this->projectDir.'/data/body-plates.php';
    }
}
