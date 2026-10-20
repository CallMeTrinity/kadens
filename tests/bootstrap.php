<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

/*
 * La base de test se cale sur les entités à chaque lancement, sans commande à
 * retenir. Elle se construit par `schema:create` (comme en CI), pas par les
 * migrations : une migration jouée en dev la laissait en retard, et toute la
 * suite tombait sur une colonne inconnue. Écart constaté → on la reconstruit
 * de zéro ; aucun écart → on n'y touche pas, le surcoût est un diff de schéma.
 */
(static function (): void {
    $kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
    $kernel->boot();

    /** @var Doctrine\ORM\EntityManagerInterface $em */
    $em = $kernel->getContainer()->get('doctrine')->getManager();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    $schemaTool = new Doctrine\ORM\Tools\SchemaTool($em);

    if ([] !== $schemaTool->getUpdateSchemaSql($metadata)) {
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($metadata);
    }

    $kernel->shutdown();
})();
