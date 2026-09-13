<?php

declare(strict_types=1);

use App\Kernel;
use App\Shared\Type\Scalar;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__ . '/../.env');

return new Application(new Kernel(
    Scalar::string($_SERVER['APP_ENV'] ?? null, 'dev'),
    Scalar::bool($_SERVER['APP_DEBUG'] ?? null, true),
));
