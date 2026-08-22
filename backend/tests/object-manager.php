<?php

declare(strict_types=1);

// Lets phpstan-doctrine understand entity mappings, so it can type repository results
// and catch invalid DQL. Not used at runtime.
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$env = $_SERVER['APP_ENV'] ?? 'dev';

$kernel = new Kernel(
    is_string($env) ? $env : 'dev',
    filter_var($_SERVER['APP_DEBUG'] ?? true, \FILTER_VALIDATE_BOOL),
);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
