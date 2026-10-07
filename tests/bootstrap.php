<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Same as Kernel::boot(): the application works in UTC regardless of php.ini's date.timezone.
date_default_timezone_set('UTC');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
