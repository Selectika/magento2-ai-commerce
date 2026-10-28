<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Model;

use Magento\Checkout\Model\Session;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Session-local delivery through Magento private content; never cache shopper data in page HTML. */
class EventBuffer
{
    private const SESSION_KEY = 'selectika_native_events';

    public function __construct(private Session $session, private Config $config, private StoreManagerInterface $stores, private CookieManagerInterface $cookies)
    {
    }

    public function isAllowed(): bool
    {
        $storeId = (int) $this->stores->getStore()->getId();
        return $this->config->enabled('storefront/tracking_enabled', $storeId)
            && $this->config->enabled('storefront/enabled', $storeId)
            && (!$this->config->requiresConsent($storeId) || $this->cookies->getCookie(Config::CONSENT_COOKIE) === '1');
    }

    public function append(string $name, array $payload, ?string $eventId = null): void
    {
        if (!$this->isAllowed()) {
            return;
        }
        $key = $this->sessionKey();
        $events = $this->session->getData($key) ?: [];
        $eventId = $eventId ?? bin2hex(random_bytes(16));
        $events[$eventId] = array_merge($payload, [
            'schema_version' => 1,
            'event_id' => $eventId,
            'occurred_at' => gmdate('c'),
            'event_name' => $name,
            'event_selectika' => $name,
            'selectika_feature' => 'commerce',
            'selectika_action' => $name,
            'source' => 'storefront',
        ]);
        $this->session->setData($key, array_slice($events, -20, null, true));
    }

    public function drain(): array
    {
        $key = $this->sessionKey();
        $events = $this->session->getData($key) ?: [];
        $this->session->unsetData($key);
        return $this->isAllowed() ? array_values($events) : [];
    }

    private function sessionKey(): string
    {
        return self::SESSION_KEY . '_' . (int) $this->stores->getStore()->getId();
    }
}
