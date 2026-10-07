<?php

declare(strict_types=1);

namespace App\Service\Audio;

/** Expected pipeline failure with an admin-readable message (no secrets). */
final class AudioGenerationException extends \RuntimeException
{
}
