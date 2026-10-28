<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use RuntimeException;
use Throwable;

/** Immutable pages stay outside pub/. Readers never see an incomplete generation. */
class SnapshotStore
{
    private const PAGE_ROWS = 250;
    private const PAGE_BYTES = 2097152;
    private const MAX_ROW_BYTES = 524288;
    private const CURRENT_MAX_AGE = 86400;
    private const RETENTION_SECONDS = 172800;
    private string $root;

    public function __construct(DirectoryList $directories)
    {
        $this->root = rtrim($directories->getPath(DirectoryList::VAR_DIR), '/') . '/selectika/catalog';
    }

    /** @param iterable<array<string, mixed>> $rows @param array<string, scalar> $metadata */
    public function publish(int $storeId, iterable $rows, array $metadata): array
    {
        $directory = $this->directory($storeId);
        $this->mkdir($directory);
        $lock = fopen($directory . '/export.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Catalog export lock is unavailable.');
        }
        chmod($directory . '/export.lock', 0660);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Catalog export is already running.');
        }
        $snapshot = bin2hex(random_bytes(16));
        $temporary = $directory . '/build-' . $snapshot;
        try {
            $this->mkdir($temporary);
            $pages = [];
            $page = [];
            $bytes = 0;
            $count = 0;
            foreach ($rows as $row) {
                $rowBytes = strlen($this->json($row));
                if ($rowBytes > self::MAX_ROW_BYTES) {
                    throw new RuntimeException('A catalog row exceeds the export size limit.');
                }
                if ($page !== [] && (count($page) >= self::PAGE_ROWS || $bytes + $rowBytes > self::PAGE_BYTES)) {
                    $pages[] = $this->writePage($temporary, count($pages) + 1, $page);
                    $page = [];
                    $bytes = 0;
                }
                $page[] = $row;
                $bytes += $rowBytes;
                ++$count;
            }
            if ($count === 0) {
                throw new RuntimeException('Empty catalog export was not published; the prior snapshot is retained.');
            }
            if ($page !== []) {
                $pages[] = $this->writePage($temporary, count($pages) + 1, $page);
            }
            $manifest = [
                'schema_version' => 1,
                'snapshot_id' => $snapshot,
                'store_id' => $storeId,
                'generated_at' => time(),
                'total_rows' => $count,
                'metadata' => $metadata,
                'pages' => $pages,
            ];
            $this->write($temporary . '/manifest.json', $this->json($manifest));
            if (!rename($temporary, $directory . '/' . $snapshot)) {
                throw new RuntimeException('Cannot publish the catalog snapshot.');
            }
            $pointer = $directory . '/current-' . $snapshot . '.tmp';
            $this->write($pointer, $this->json(['snapshot_id' => $snapshot]));
            if (!rename($pointer, $directory . '/current.json')) {
                @unlink($pointer);
                throw new RuntimeException('Cannot publish the current catalog pointer.');
            }
            $this->prune($directory, $snapshot);
            return $manifest;
        } catch (Throwable $error) {
            $this->removeOwnedDirectory($temporary);
            throw $error;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed> */
    public function manifest(int $storeId, ?string $snapshot = null): array
    {
        $current = $snapshot === null;
        $directory = $this->directory($storeId);
        if ($current) {
            $pointer = $this->readJson($directory . '/current.json');
            $snapshot = is_string($pointer['snapshot_id'] ?? null) ? $pointer['snapshot_id'] : '';
        }
        if (!is_string($snapshot) || preg_match('/^[a-f0-9]{32}$/D', $snapshot) !== 1) {
            throw new RuntimeException('Invalid catalog snapshot.');
        }
        $manifest = $this->readJson($directory . '/' . $snapshot . '/manifest.json');
        if (($manifest['store_id'] ?? null) !== $storeId || ($manifest['snapshot_id'] ?? null) !== $snapshot
            || ($manifest['schema_version'] ?? null) !== 1 || !is_array($manifest['pages'] ?? null)
            || !is_int($manifest['generated_at'] ?? null) || !is_int($manifest['total_rows'] ?? null) || $manifest['total_rows'] < 1) {
            throw new RuntimeException('Catalog snapshot metadata is invalid.');
        }
        $totalRows = 0;
        if ($manifest['pages'] === [] || !array_is_list($manifest['pages'])) {
            throw new RuntimeException('Catalog snapshot has no complete page sequence.');
        }
        foreach ($manifest['pages'] as $index => $record) {
            if (!is_array($record) || ($record['number'] ?? null) !== $index + 1
                || !is_int($record['rows'] ?? null) || $record['rows'] < 1 || $record['rows'] > self::PAGE_ROWS
                || !is_string($record['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $record['sha256']) !== 1) {
                throw new RuntimeException('Catalog snapshot page sequence is invalid.');
            }
            $totalRows += $record['rows'];
        }
        if ($totalRows !== $manifest['total_rows']) {
            throw new RuntimeException('Catalog snapshot page sequence is incomplete.');
        }
        $maxAge = $current ? self::CURRENT_MAX_AGE : self::RETENTION_SECONDS;
        if (time() - $manifest['generated_at'] > $maxAge || $manifest['generated_at'] > time() + 60) {
            throw new RuntimeException('Catalog snapshot is stale.');
        }
        return $manifest;
    }

    /** @return array{manifest: array<string, mixed>, results: array} */
    public function page(int $storeId, int $page, ?string $snapshot = null): array
    {
        $manifest = $this->manifest($storeId, $snapshot);
        $record = $manifest['pages'][$page - 1] ?? null;
        if ($page < 1 || !is_array($record) || ($record['number'] ?? null) !== $page) {
            throw new RuntimeException('Catalog page does not exist.');
        }
        $path = $this->directory($storeId) . '/' . $manifest['snapshot_id'] . '/page-' . $page . '.json';
        $raw = $this->read($path);
        if (!hash_equals((string) ($record['sha256'] ?? ''), hash('sha256', $raw))) {
            throw new RuntimeException('Catalog page integrity check failed.');
        }
        $rows = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($rows) || count($rows) !== $record['rows']) {
            throw new RuntimeException('Catalog page row count differs from its manifest.');
        }
        return ['manifest' => $manifest, 'results' => $rows];
    }

    private function directory(int $storeId): string
    {
        if ($storeId <= 0) {
            throw new RuntimeException('A storefront store view is required.');
        }
        return $this->root . '/' . $storeId;
    }

    private function writePage(string $directory, int $page, array $rows): array
    {
        $raw = $this->json($rows);
        $this->write($directory . '/page-' . $page . '.json', $raw);
        return ['number' => $page, 'rows' => count($rows), 'sha256' => hash('sha256', $raw)];
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function mkdir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 02770, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create the catalog export directory.');
        }
        // CLI/cron and PHP-FPM commonly run as separate users in one Magento group.
        chmod($path, 02770);
    }

    private function write(string $path, string $value): void
    {
        if (file_put_contents($path, $value, LOCK_EX) !== strlen($value)) {
            throw new RuntimeException('Cannot persist a complete catalog export file.');
        }
        chmod($path, 0660);
    }

    private function read(string $path): string
    {
        $value = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if ($value === false) {
            throw new RuntimeException('Catalog snapshot is not available.');
        }
        return $value;
    }

    private function readJson(string $path): array
    {
        $value = json_decode($this->read($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('Catalog metadata is not an object.');
        }
        return $value;
    }

    private function prune(string $directory, string $current): void
    {
        foreach (scandir($directory) ?: [] as $name) {
            $path = $directory . '/' . $name;
            if ($name !== $current && preg_match('/^[a-f0-9]{32}$/D', $name) === 1
                && is_dir($path) && !is_link($path) && filemtime($path) < time() - self::RETENTION_SECONDS) {
                $this->removeOwnedDirectory($path);
            }
        }
    }

    private function removeOwnedDirectory(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if (preg_match('/^(?:page-[0-9]+|manifest)\.json$/D', $name) === 1) {
                @unlink($path . '/' . $name);
            }
        }
        @rmdir($path);
    }
}
