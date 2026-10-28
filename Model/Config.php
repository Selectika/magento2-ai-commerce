<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const VERSION = '0.1.1';
    public const CONSENT_COOKIE = 'selectika_analytics_consent';

    public function __construct(private ScopeConfigInterface $config, private EncryptorInterface $encryptor)
    {
    }

    public function value(string $path, int $storeId): string
    {
        return trim((string) $this->config->getValue('selectika/' . $path, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function enabled(string $path, int $storeId): bool
    {
        return $this->value('connection/enabled', $storeId) === '1' && $this->value($path, $storeId) === '1';
    }

    public function token(int $storeId): string
    {
        return $this->encryptor->decrypt($this->value('catalog/feed_token', $storeId));
    }

    public function requiresConsent(int $storeId): bool
    {
        return $this->value('storefront/require_consent', $storeId) !== '0';
    }

    public function attributeCode(string $setting, int $storeId, string $fallback): string
    {
        $code = $this->value('catalog/' . $setting, $storeId);
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) === 1 ? $code : $fallback;
    }

    /** @return string[] */
    public function extraAttributes(int $storeId): array
    {
        return array_slice(array_values(array_unique(array_filter(
            array_map('trim', explode(',', $this->value('catalog/extra_attributes', $storeId))),
            static fn (string $value): bool => preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) === 1
        ))), 0, 30);
    }
}
