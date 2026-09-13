<?php

declare(strict_types=1);

use App\Shared\Type\Scalar;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->bootEnv(dirname(__DIR__) . '/.env');

/*
 * Compose passes `.env.local` to the containers through `env_file`, which turns
 * every line into a real environment variable — and a real variable beats every
 * `.env*` file, including `.env.test`. Left alone, the suite would assert on the
 * creator's own credentials and models instead of the fixtures, so it would pass
 * on a fresh clone and fail on a configured machine. Reloading the test files
 * with override restores them, and only for the keys they actually declare:
 * `DATABASE_URL`, which points at the service name inside Compose, is untouched.
 */
foreach (['/.env.test', '/.env.test.local'] as $file) {
    $path = dirname(__DIR__) . $file;
    if (is_file($path)) {
        $dotenv->overload($path);
    }
}

if (Scalar::bool($_SERVER['APP_DEBUG'] ?? null, true)) {
    umask(0o000);
}
