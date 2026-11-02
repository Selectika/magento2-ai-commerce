<?php
declare(strict_types=1);
namespace Selectika\AiCommerce\Controller\Cart;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\JsonFactory;
use Selectika\AiCommerce\Model\AgentCart;
abstract class AbstractAction implements HttpPostActionInterface, CsrfAwareActionInterface {
    public function __construct(private RequestInterface $request, private JsonFactory $json, private AgentCart $cart) {}
    abstract protected function action(): string;
    public function validateForCsrf(RequestInterface $request): ?bool { return $this->cart->authorized($request); }
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException { return null; }
    public function execute() {
        $result = $this->json->create()->setHeader('Cache-Control', 'private, no-store', true);
        if (!$this->cart->authorized($this->request)) { return $result->setHttpResponseCode(401)->setData(['error' => 'Unauthorized']); }
        $raw = $this->request->getContent();
        if (strlen($raw) > 131072) { return $result->setHttpResponseCode(413)->setData(['error' => 'Request too large']); }
        try { $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); if (!is_array($input)) { throw new \InvalidArgumentException(); } return $result->setData($this->cart->dispatch($this->action(), $input)); }
        catch (\Throwable $error) { return $result->setHttpResponseCode(409)->setData(['error' => 'Outcome uncertain; read current cart before retrying']); }
    }
}
