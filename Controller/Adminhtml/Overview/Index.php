<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Controller\Adminhtml\Overview;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Selectika_AiCommerce::overview';

    public function __construct(Context $context, private PageFactory $pages)
    {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->pages->create();
        $page->setActiveMenu('Selectika_AiCommerce::overview');
        $page->getConfig()->getTitle()->prepend(__('Selectika Overview'));
        return $page;
    }
}
