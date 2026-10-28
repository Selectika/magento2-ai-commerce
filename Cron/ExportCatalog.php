<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Selectika\AiCommerce\Model\Config;
use Selectika\AiCommerce\Model\ExportCatalog as Exporter;
use Throwable;

class ExportCatalog
{
    public function __construct(private StoreManagerInterface $stores, private Config $config, private Exporter $exporter, private LoggerInterface $logger)
    {
    }

    public function execute(): void
    {
        foreach ($this->stores->getStores() as $store) {
            if (!$this->config->enabled('catalog/enabled', (int) $store->getId()) || !$store->isActive()) {
                continue;
            }
            try {
                $this->exporter->export((int) $store->getId());
            } catch (Throwable $error) {
                $this->logger->error('Selectika catalog snapshot failed; prior snapshot retained.', ['store_id' => $store->getId(), 'error_type' => get_class($error)]);
            }
        }
    }
}
