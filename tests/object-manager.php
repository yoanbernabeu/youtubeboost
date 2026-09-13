<?php

declare(strict_types=1);

use App\Kernel;
use App\Shared\Type\Scalar;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__ . '/../.env');

$kernel = new Kernel(
    Scalar::string($_SERVER['APP_ENV'] ?? null, 'dev'),
    Scalar::bool($_SERVER['APP_DEBUG'] ?? null, true),
);
$kernel->boot();

$doctrine = $kernel->getContainer()->get('doctrine');
\assert($doctrine instanceof ManagerRegistry);

return $doctrine->getManager();
