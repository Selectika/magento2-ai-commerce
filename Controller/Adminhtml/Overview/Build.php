<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Controller\Adminhtml\Overview;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Selectika\AiCommerce\Model\ExportCatalogFactory;
use Throwable;

/** Builds a catalog snapshot now, the same export the hourly cron and the CLI command run. */
class Build extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Selectika_AiCommerce::overview';

    public function __construct(
        Context $context,
        private ExportCatalogFactory $exporters,
        private StoreManagerInterface $stores,
        private LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');
        $storeId = (int) $this->getRequest()->getParam('store_id');
        try {
            $store = $this->stores->getStore($storeId);
            if ($storeId < 1 || !$store->isActive()) {
                throw new \InvalidArgumentException('Choose an active store view.');
            }
            $manifest = $this->exporters->create()->export($storeId);
            $this->messageManager->addSuccessMessage(__(
                'Built snapshot for "%1": %2 rows in %3 pages.',
                $store->getCode(),
                $manifest['total_rows'],
                count($manifest['pages'])
            ));
        } catch (Throwable $error) {
            // Exception text can carry merchant values; log the type and place, show a fixed message.
            $this->logger->error('Selectika catalog export from the admin failed; prior snapshot retained.', [
                'error_type' => get_debug_type($error), 'error_file' => basename($error->getFile()), 'error_line' => $error->getLine(),
            ]);
            $this->messageManager->addErrorMessage(__(
                'The catalog export failed and the previous snapshot was kept. Check that catalog export is enabled for this store view, then see the Magento logs. Very large catalogs should be exported with the hourly job or "bin/magento selectika:catalog:export".'
            ));
        }
        return $redirect;
    }
}
