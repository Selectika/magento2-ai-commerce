<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as ScheduleFactory;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Selectika\AiCommerce\Model\Config;
use Selectika\AiCommerce\Model\Health;
use Selectika\AiCommerce\Model\SnapshotStore;
use Throwable;

/** Collects the facts for every active store view and hands the template a ready checklist. */
class Overview extends Template
{
    public function __construct(
        Context $context,
        private StoreManagerInterface $stores,
        private Config $config,
        private SnapshotStore $snapshots,
        private ScheduleFactory $schedules,
        private ProductMetadataInterface $metadata,
        private Health $health,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return list<array{id: int, code: string, name: string, summary: string, checks: list<array<string, string>>, report: string}> */
    public function storeViews(): array
    {
        $now = time();
        $cron = $this->lastCronSuccess();
        $views = [];
        foreach ($this->stores->getStores() as $store) {
            $id = (int) $store->getId();
            if ($id < 1 || !$store->isActive()) {
                continue;
            }
            $generated = null;
            $rows = null;
            $snapshotId = '';
            try {
                $manifest = $this->snapshots->manifest($id);
                $generated = $manifest['generated_at'];
                $rows = $manifest['total_rows'];
                $snapshotId = (string) $manifest['snapshot_id'];
            } catch (Throwable) {
                // Missing, stale or invalid: the checklist reports it as "no usable snapshot".
            }
            $facts = [
                'connection_enabled' => $this->config->value('connection/enabled', $id) === '1',
                'retailer_name' => $this->config->value('connection/retailer_name', $id),
                'publisher_id' => $this->config->value('connection/publisher_id', $id),
                'catalog_enabled' => $this->config->value('catalog/enabled', $id) === '1',
                'token_valid' => $this->tokenValid($id),
                'secure_base_url' => parse_url((string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true), PHP_URL_SCHEME) === 'https',
                'cron_last_success' => $cron,
                'snapshot_generated_at' => $generated,
                'snapshot_rows' => $rows,
                'now' => $now,
            ];
            $checks = $this->health->checks($facts);
            $views[] = [
                'id' => $id,
                'code' => (string) $store->getCode(),
                'name' => (string) $store->getName(),
                'summary' => $this->health->summary($checks),
                'checks' => $checks,
                'report' => $this->report($store->getCode(), $checks, $snapshotId, $now),
            ];
        }
        return $views;
    }

    public function version(): string
    {
        return Config::VERSION;
    }

    public function configUrl(int $storeId): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'selectika', 'store' => $storeId]);
    }

    private function tokenValid(int $storeId): bool
    {
        try {
            return preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $this->config->token($storeId)) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function lastCronSuccess(): ?int
    {
        try {
            $item = $this->schedules->create()
                ->addFieldToFilter('job_code', 'selectika_catalog_export')
                ->addFieldToFilter('status', 'success')
                ->setOrder('executed_at', 'DESC')
                ->setPageSize(1)
                ->getFirstItem();
            $at = (string) $item->getData('executed_at');
            // cron_schedule stores UTC timestamps.
            return $at === '' ? null : (int) (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    /** A paste-ready support report. It never contains the token, credentials or product data. */
    private function report(string $storeCode, array $checks, string $snapshotId, int $now): string
    {
        $lines = [
            'Selectika AI Commerce ' . Config::VERSION,
            'Magento ' . $this->metadata->getVersion() . ' (' . $this->metadata->getEdition() . '), PHP ' . PHP_VERSION,
            'Store view: ' . $storeCode,
            'Snapshot: ' . ($snapshotId !== '' ? $snapshotId : 'none'),
            'Report time (UTC): ' . gmdate('c', $now),
        ];
        foreach ($checks as $check) {
            $lines[] = strtoupper($check['status']) . ' ' . $check['title'] . ': ' . $check['detail'];
        }
        return implode("\n", $lines);
    }
}
