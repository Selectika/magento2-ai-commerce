<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Block;

use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template;
use Selectika\AiCommerce\Model\Config;

class Bootstrap extends Template
{
    public function __construct(
        Template\Context $context,
        private Config $config,
        private Registry $registry,
        private Configurable $configurable,
        private Json $json,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->config->enabled('storefront/enabled', (int) $this->_storeManager->getStore()->getId());
    }

    /** Public catalog context only. No session, customer, cart or order data enters cached HTML. */
    public function publicConfig(): array
    {
        $store = $this->_storeManager->getStore();
        $storeId = (int) $store->getId();
        $product = $this->registry->registry('current_product');
        $productContext = null;
        if ($product instanceof Product) {
            $variants = [];
            if ($product->getTypeId() === 'configurable') {
                foreach ($this->configurable->getUsedProducts($product) as $variant) {
                    $variants[(string) $variant->getId()] = (string) $variant->getSku();
                }
            }
            $productContext = ['sku' => (string) $product->getSku(), 'entityId' => (string) $product->getId(), 'name' => (string) $product->getName(), 'variants' => $variants];
        }
        return [
            'version' => Config::VERSION,
            'retailerName' => $this->config->value('connection/retailer_name', $storeId),
            'publisherId' => $this->config->value('connection/publisher_id', $storeId),
            'storeCode' => (string) $store->getCode(),
            'currency' => (string) $store->getBaseCurrencyCode(),
            'trackingEnabled' => $this->config->enabled('storefront/tracking_enabled', $storeId),
            'requireConsent' => $this->config->requiresConsent($storeId),
            'pageType' => (string) $this->getRequest()->getFullActionName(),
            'product' => $productContext,
        ];
    }

    public function mageInit(): string
    {
        return $this->json->serialize(['Selectika_AiCommerce/js/bootstrap' => $this->publicConfig()]);
    }
}
