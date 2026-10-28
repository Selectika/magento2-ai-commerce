<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Controller\Feed;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Selectika\AiCommerce\Model\Config;
use Selectika\AiCommerce\Model\FeedAuthorization;
use Selectika\AiCommerce\Model\SnapshotStore;
use Throwable;

class Catalog implements HttpGetActionInterface
{
    public function __construct(
        private Http $request,
        private JsonFactory $json,
        private StoreManagerInterface $stores,
        private Config $config,
        private FeedAuthorization $authorization,
        private SnapshotStore $snapshots
    ) {
    }

    public function execute(): Json
    {
        $response = $this->json->create();
        $response->setHeader('Cache-Control', 'private, no-store, max-age=0', true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true);
        try {
            $storeCode = (string) $this->request->getParam('store', '');
            if ($storeCode === '' || $storeCode === 'admin') {
                return $response->setHttpResponseCode(400)->setData(['error' => 'store_view_required']);
            }
            $store = $this->stores->getStore($storeCode);
            $storeId = (int) $store->getId();
            if (!$this->config->enabled('catalog/enabled', $storeId) || !$store->isActive()
                || !$this->authorization->accepts((string) $this->request->getHeader('Authorization'), $this->config->token($storeId), $this->request->isSecure())) {
                return $response->setHttpResponseCode(401)->setData(['error' => 'unauthorized']);
            }
            $pageValue = (string) $this->request->getParam('page', '1');
            $snapshot = $this->request->getParam('snapshot');
            if (preg_match('/^[1-9][0-9]{0,5}$/D', $pageValue) !== 1
                || ($snapshot !== null && (!is_string($snapshot) || preg_match('/^[a-f0-9]{32}$/D', $snapshot) !== 1))) {
                return $response->setHttpResponseCode(400)->setData(['error' => 'invalid_page']);
            }
            $page = (int) $pageValue;
            $data = $this->snapshots->page($storeId, $page, $snapshot);
            $manifest = $data['manifest'];
            $next = null;
            if ($page < count($manifest['pages'])) {
                $base = $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true);
                if (parse_url($base, PHP_URL_SCHEME) !== 'https') {
                    return $response->setHttpResponseCode(503)->setData(['error' => 'secure_store_url_required']);
                }
                $next = rtrim($base, '/') . '/selectika/feed/catalog?' . http_build_query([
                    'store' => $store->getCode(),
                    'snapshot' => $manifest['snapshot_id'],
                    'page' => $page + 1,
                ], '', '&', PHP_QUERY_RFC3986);
            }
            return $response->setData([
                'results' => $data['results'],
                'next' => $next,
                'snapshot_id' => $manifest['snapshot_id'],
                'generated_at' => gmdate('c', $manifest['generated_at']),
                'total_rows' => $manifest['total_rows'],
                'page' => $page,
                'total_pages' => count($manifest['pages']),
                'store_code' => $store->getCode(),
                'module_version' => Config::VERSION,
            ]);
        } catch (Throwable $error) {
            // Never return an empty successful feed on auth, generation, expiry or integrity failure.
            return $response->setHttpResponseCode(503)->setData(['error' => 'catalog_snapshot_unavailable']);
        }
    }
}
