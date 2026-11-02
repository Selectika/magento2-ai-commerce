<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Model;

/**
 * Turns the facts about one store view into the ordered setup checklist shown on the admin overview.
 * It reads nothing from Magento, so every rule is testable without a framework.
 */
class Health
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    private const SNAPSHOT_MAX_AGE = 86400;
    private const CRON_MAX_AGE = 10800;

    /**
     * @param array{
     *   connection_enabled: bool, retailer_name: string, publisher_id: string,
     *   catalog_enabled: bool, token_valid: bool, secure_base_url: bool,
     *   cron_last_success: ?int, snapshot_generated_at: ?int, snapshot_rows: ?int, now: int
     * } $facts
     * @return list<array{id: string, status: string, title: string, detail: string, action: string}>
     */
    public function checks(array $facts): array
    {
        $now = $facts['now'];
        $checks = [];

        $connected = $facts['connection_enabled'] && $facts['retailer_name'] !== '';
        $checks[] = $this->check('connection', $connected ? self::OK : self::FAIL, 'Selectika account',
            $connected
                ? 'Connected as "' . $facts['retailer_name'] . '".'
                : 'Enable the connection and enter the existing Selectika retailer name.',
            $connected ? '' : 'config');

        $checks[] = $this->check('publisher', $facts['publisher_id'] !== '' ? self::OK : self::WARN, 'Analytics publisher ID',
            $facts['publisher_id'] !== ''
                ? 'Publisher ID is set.'
                : 'Not set. Catalog export works, but storefront analytics need the publisher ID from Selectika.',
            $facts['publisher_id'] !== '' ? '' : 'config');

        $feedReady = $facts['catalog_enabled'] && $facts['token_valid'];
        $checks[] = $this->check('feed', $feedReady ? self::OK : self::FAIL, 'Catalog feed and token',
            $feedReady
                ? 'Catalog export is on and a valid read-only token is stored.'
                : ($facts['catalog_enabled']
                    ? 'The read-only feed token is missing or shorter than 32 characters; the feed answers 401 until it is fixed.'
                    : 'Catalog export is off, so the feed answers 401.'),
            $feedReady ? '' : 'config');

        $checks[] = $this->check('https', $facts['secure_base_url'] ? self::OK : self::FAIL, 'HTTPS store URL',
            $facts['secure_base_url']
                ? 'The store URL uses HTTPS.'
                : 'The feed is served over HTTPS only. Set an https:// base URL for this store view.',
            '');

        $cron = $facts['cron_last_success'];
        if ($cron === null) {
            $cronStatus = self::WARN;
            $cronDetail = 'The hourly export job has not run yet. Check that Magento cron is running.';
        } elseif ($now - $cron > self::CRON_MAX_AGE) {
            $cronStatus = self::WARN;
            $cronDetail = 'The last successful hourly export was ' . $this->ago($now - $cron) . '. Check that Magento cron is running.';
        } else {
            $cronStatus = self::OK;
            $cronDetail = 'Last successful hourly export ' . $this->ago($now - $cron) . '.';
        }
        $checks[] = $this->check('cron', $cronStatus, 'Hourly export job', $cronDetail, '');

        $generated = $facts['snapshot_generated_at'];
        if ($generated === null) {
            $snapshotStatus = self::FAIL;
            $snapshotDetail = 'No usable catalog snapshot. The feed answers 503 until one is built.';
            $snapshotAction = $feedReady ? 'build' : '';
        } elseif ($now - $generated > self::SNAPSHOT_MAX_AGE) {
            $snapshotStatus = self::FAIL;
            $snapshotDetail = 'The snapshot is ' . $this->ago($now - $generated) . ' and too old to serve.';
            $snapshotAction = $feedReady ? 'build' : '';
        } else {
            $snapshotStatus = self::OK;
            $snapshotDetail = (string) $facts['snapshot_rows'] . ' rows, built ' . $this->ago($now - $generated) . '.';
            $snapshotAction = '';
        }
        $checks[] = $this->check('snapshot', $snapshotStatus, 'Catalog snapshot', $snapshotDetail, $snapshotAction);

        $checks[] = $this->check('fetch', self::OK, 'Live feed test',
            'Run the test to request the feed the way Selectika does, through your public store URL.',
            $feedReady && $facts['secure_base_url'] ? 'test' : '');

        return $checks;
    }

    /** @param list<array{status: string}> $checks */
    public function summary(array $checks): string
    {
        foreach ($checks as $check) {
            if ($check['status'] === self::FAIL) {
                return self::FAIL;
            }
        }
        foreach ($checks as $check) {
            if ($check['status'] === self::WARN) {
                return self::WARN;
            }
        }
        return self::OK;
    }

    /** @return array{id: string, status: string, title: string, detail: string, action: string} */
    private function check(string $id, string $status, string $title, string $detail, string $action): array
    {
        return ['id' => $id, 'status' => $status, 'title' => $title, 'detail' => $detail, 'action' => $action];
    }

    private function ago(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return match (true) {
            $seconds < 90 => 'moments ago',
            $seconds < 5400 => intdiv($seconds, 60) . ' minutes ago',
            $seconds < 172800 => intdiv($seconds, 3600) . ' hours ago',
            default => intdiv($seconds, 86400) . ' days ago',
        };
    }
}
