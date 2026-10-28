<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Observer;

use Magento\Checkout\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Locale\FormatInterface;
use Psr\Log\LoggerInterface;
use Selectika\AiCommerce\Model\CommerceItems;
use Selectika\AiCommerce\Model\EventBuffer;
use Throwable;

class CartAdded implements ObserverInterface
{
    public function __construct(private EventBuffer $buffer, private Session $session, private CommerceItems $items, private FormatInterface $locale, private LoggerInterface $logger)
    {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->buffer->isAllowed()) {
                return;
            }
            $product = $observer->getEvent()->getProduct();
            $request = $observer->getEvent()->getRequest();
            $qty = (float) $this->locale->getNumber($request->getParam('qty', 1));
            if (!$product || $qty <= 0 || !is_finite($qty)) {
                return;
            }
            $quote = $this->session->getQuote();
            // Core representProduct compares selected options as well as the parent ID.
            // A cart may contain several different variants of the same configurable.
            $item = $quote->getItemByProduct($product);
            if ($item && !$item->getParentItem()) {
                $line = $this->items->quoteItem($item, $qty);
                $this->buffer->append('add_to_cart', ['items' => [$line], 'currency' => (string) $quote->getQuoteCurrencyCode(), 'value' => $line['price'] * $qty]);
            }
        } catch (Throwable $error) {
            $this->logger->warning('Selectika cart analytics event omitted.', ['error_type' => get_class($error)]);
        }
    }
}
