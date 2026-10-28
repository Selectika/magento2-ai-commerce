<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Model;

use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use RuntimeException;

class ExportCatalog
{
    public function __construct(
        private StoreManagerInterface $stores,
        private Config $config,
        private CatalogRows $rows,
        private SnapshotStore $snapshots,
        private Emulation $emulation
    ) {
    }

    public function export(int $storeId): array
    {
        $store = $this->stores->getStore($storeId);
        if (!$this->config->enabled('catalog/enabled', $storeId) || !$store->isActive()) {
            throw new RuntimeException('Catalog export is disabled for this store view.');
        }
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            return $this->snapshots->publish($storeId, $this->rows->rows($store), [
                'store_code' => (string) $store->getCode(),
                'currency' => (string) $store->getBaseCurrencyCode(),
                'retailer_name' => $this->config->value('connection/retailer_name', $storeId),
                'module_version' => Config::VERSION,
            ]);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }
}
