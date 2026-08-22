<?php

declare(strict_types=1);

// Lets phpstan-symfony resolve console command types. Not used at runtime.
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$env = $_SERVER['APP_ENV'] ?? 'dev';

$kernel = new Kernel(
    is_string($env) ? $env : 'dev',
    filter_var($_SERVER['APP_DEBUG'] ?? true, \FILTER_VALIDATE_BOOL),
);

return new Application($kernel);
