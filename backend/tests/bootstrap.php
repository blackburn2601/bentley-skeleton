<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Force the test environment BEFORE loading .env files.
//
// phpunit.dist.xml sets APP_ENV via <server force="true">, but a real environment variable —
// which compose sets on the container — is what Dotenv consults, and it wins. Without this,
// running the suite inside the dev container boots the DEV kernel: no test service container,
// and the dev DATABASE_URL, so a test run would write to the development database.
//
// A test run must never be able to boot anything but the test environment.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
putenv('APP_ENV=test');

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ((bool) ($_SERVER['APP_DEBUG'] ?? false)) {
    umask(0o000);
}
