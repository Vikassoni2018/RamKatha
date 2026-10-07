<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // All timestamps are created and stored in UTC. Display conversion to the configured
        // application timezone (APP_TIMEZONE) happens only in the presentation layer (Twig/forms).
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
