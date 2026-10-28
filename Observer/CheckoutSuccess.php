<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Observer;

use Magento\Checkout\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Selectika\AiCommerce\Model\CommerceItems;
use Selectika\AiCommerce\Model\EventBuffer;
use Throwable;

class CheckoutSuccess implements ObserverInterface
{
    public function __construct(private EventBuffer $buffer, private Session $session, private CommerceItems $items, private LoggerInterface $logger)
    {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->buffer->isAllowed()) {
                return;
            }
            $order = $this->session->getLastRealOrder();
            if (!$order->getId() || in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_CLOSED], true)) {
                return;
            }
            $items = array_map(fn ($item): array => $this->items->orderItem($item), array_slice($order->getAllVisibleItems(), 0, 100));
            $this->buffer->append('purchase', [
                'transaction_id' => (string) $order->getIncrementId(),
                'currency' => (string) $order->getOrderCurrencyCode(),
                'value' => (float) $order->getGrandTotal(),
                'tax' => (float) $order->getTaxAmount(),
                'shipping' => (float) $order->getShippingAmount(),
                'items' => $items,
            ], 'magento-order-' . hash('sha256', $order->getStoreId() . ':' . $order->getId()));
        } catch (Throwable $error) {
            $this->logger->warning('Selectika checkout analytics event omitted.', ['error_type' => get_class($error)]);
        }
    }
}
