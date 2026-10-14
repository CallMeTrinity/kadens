<?php

// Chargeur d'EntityManager pour phpstan-doctrine : il lit le mapping réel
// (types de colonnes, nullabilité, DQL) au lieu de le deviner. Ne se connecte
// pas à la base, seul le serverVersion de DATABASE_URL est lu.

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
