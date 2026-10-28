<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Model;

/** This allowlist deliberately excludes customer identities, addresses and payment data. */
class CommerceItems
{
    public function quoteItem(\Magento\Quote\Model\Quote\Item $item, ?float $quantity = null): array
    {
        return [
            // Configurable getSku() may resolve the selected child's SKU in a quote.
            'item_id' => (string) $item->getProduct()->getData('sku'),
            'item_sku' => (string) $item->getSku(),
            'item_variant' => (string) $item->getSku(),
            'item_name' => (string) $item->getName(),
            'quantity' => $quantity ?? (float) $item->getQty(),
            'price' => (float) $item->getCalculationPrice(),
        ];
    }

    public function orderItem(\Magento\Sales\Model\Order\Item $item): array
    {
        $options = $item->getProductOptions() ?: [];
        // Magento stores the selected child SKU in simple_sku for configurable items.
        // Success-page events normally still have the catalog product available.
        // Deleted products cannot reliably reconstruct a configurable parent SKU.
        $variantSku = (string) ($options['simple_sku'] ?? $item->getSku());
        $parentSku = (string) $item->getSku();
        $product = $item->getProduct();
        if ((!$product || !$product->getData('sku')) && $item->getProductType() === 'configurable') {
            throw new \RuntimeException('Configurable order parent identity is unavailable.');
        }
        if ($product && $product->getData('sku')) {
            $parentSku = (string) $product->getData('sku');
        }
        return [
            'item_id' => $parentSku,
            'item_sku' => $variantSku,
            'item_variant' => $variantSku,
            'item_name' => (string) $item->getName(),
            'quantity' => (float) $item->getQtyOrdered(),
            'price' => (float) $item->getPrice(),
        ];
    }
}
