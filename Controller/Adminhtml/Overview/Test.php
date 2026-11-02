<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Controller\Adminhtml\Overview;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Selectika\AiCommerce\Model\Config;
use Selectika\AiCommerce\Model\FeedProbe;
use Throwable;

/** Requests the store's own public feed URL with its stored token, the way Selectika's reader does. */
class Test extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Selectika_AiCommerce::overview';

    public function __construct(
        Context $context,
        private ClientFactory $clients,
        private StoreManagerInterface $stores,
        private Config $config,
        private FeedProbe $probe
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');
        $storeId = (int) $this->getRequest()->getParam('store_id');
        try {
            $store = $this->stores->getStore($storeId);
            $base = (string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true);
            $token = $this->config->token($storeId);
            if ($storeId < 1 || !$store->isActive() || parse_url($base, PHP_URL_SCHEME) !== 'https' || $token === '') {
                throw new \InvalidArgumentException('The store view needs an https:// base URL and a feed token.');
            }
            // The token only ever goes to this store's own base URL, in the Authorization header.
            $client = $this->clients->create();
            $client->setTimeout(20);
            $client->addHeader('Authorization', 'Bearer ' . $token);
            $client->get(rtrim($base, '/') . '/selectika/feed/catalog/store/' . rawurlencode((string) $store->getCode()) . '/');
            $result = $this->probe->interpret((int) $client->getStatus(), (string) $client->getBody());
        } catch (Throwable) {
            $result = $this->probe->interpret(0, '');
        }
        $result['ok']
            ? $this->messageManager->addSuccessMessage(__($result['message']))
            : $this->messageManager->addErrorMessage(__($result['message']));
        return $redirect;
    }
}
