<?php

declare(strict_types=1);

namespace App\Service\Donation;

use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Effective UPI payee configuration: admin settings first, environment variables as fallback. */
final class UpiConfiguration
{
    /** NPCI VPA format: handle@psp */
    private const VPA_PATTERN = '/^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z][a-zA-Z0-9.\-]{1,64}$/';

    public function __construct(
        private readonly SettingsService $settings,
        #[Autowire(env: 'UPI_ID')]
        private readonly string $envUpiId,
        #[Autowire(env: 'UPI_PAYEE_NAME')]
        private readonly string $envPayeeName,
    ) {
    }

    public function getUpiId(): string
    {
        $id = trim($this->settings->getString(SettingsRegistry::UPI_ID));

        return '' !== $id ? $id : trim($this->envUpiId);
    }

    public function getPayeeName(): string
    {
        $name = trim($this->settings->getString(SettingsRegistry::UPI_PAYEE_NAME));
        if ('' === $name) {
            $name = trim($this->envPayeeName);
        }

        return '' !== $name ? $name : $this->settings->getString(SettingsRegistry::SITE_NAME);
    }

    public function getCurrency(): string
    {
        return 'INR';
    }

    public function isConfigured(): bool
    {
        return null === $this->getConfigurationError();
    }

    public function getConfigurationError(): ?string
    {
        $id = $this->getUpiId();
        if ('' === $id) {
            return 'UPI is not configured: set the UPI ID in Admin → Settings or UPI_ID in the environment.';
        }
        if (!preg_match(self::VPA_PATTERN, $id)) {
            return \sprintf('The configured UPI ID "%s" is not a valid VPA (expected name@bank).', $id);
        }

        return null;
    }

    public static function isValidVpa(string $vpa): bool
    {
        return 1 === preg_match(self::VPA_PATTERN, $vpa);
    }
}
