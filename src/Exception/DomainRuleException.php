<?php

declare(strict_types=1);

namespace App\Exception;

/** A business rule prevented the requested operation (e.g. invalid workflow transition). */
class DomainRuleException extends \RuntimeException
{
}
