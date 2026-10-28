<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\CustomerData;

use Magento\Checkout\Model\Session;
use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Selectika\AiCommerce\Model\CommerceItems;
use Selectika\AiCommerce\Model\EventBuffer;
use Throwable;

class Events implements SectionSourceInterface
{
    public function __construct(private EventBuffer $buffer, private Session $session, private CommerceItems $items, private LoggerInterface $logger, private StoreManagerInterface $stores)
    {
    }

    public function getSectionData(): array
    {
        try {
            $storeCode = (string) $this->stores->getStore()->getCode();
            if (!$this->buffer->isAllowed()) {
                $this->buffer->drain();
                return ['store_code' => $storeCode, 'events' => [], 'cart' => null];
            }
            $quote = $this->session->getQuote();
            $items = array_map(fn ($item): array => $this->items->quoteItem($item), array_slice($quote->getAllVisibleItems(), 0, 100));
            return [
                'store_code' => $storeCode,
                'events' => $this->buffer->drain(),
                'cart' => ['items' => $items, 'currency' => (string) $quote->getQuoteCurrencyCode(), 'value' => (float) $quote->getSubtotal()],
            ];
        } catch (Throwable $error) {
            $this->logger->warning('Selectika private analytics section unavailable.', ['error_type' => get_class($error)]);
            return ['store_code' => null, 'events' => [], 'cart' => null];
        }
    }
}
