<?php
declare(strict_types=1);
namespace Selectika\AiCommerce\Controller\Commerce;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Escaper;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Cart;
use Magento\Checkout\Model\Session;
use Magento\Store\Model\StoreManagerInterface;
use Selectika\AiCommerce\Model\Config;

/** GET is confirmation only. POST consumes one ticket and preserves the browser quote. */
class Review implements HttpGetActionInterface, HttpPostActionInterface
{
    public function __construct(
        private Http $request, private RawFactory $raw, private RedirectFactory $redirect,
        private FormKey $formKey, private Validator $validator, private Escaper $escape,
        private Curl $http, private LockManagerInterface $locks, private ProductRepositoryInterface $products,
        private \Selectika\AiCommerce\Model\AgentVariant $variants, private Cart $cart, private Session $session, private StoreManagerInterface $stores, private Config $config
    ) {}

    public function execute(): ResultInterface
    {
        $response = $this->raw->create();
        $response->setHeader('Cache-Control', 'private, no-store', true)
            ->setHeader('Referrer-Policy', 'no-referrer', true)
            ->setHeader('Content-Type', 'text/html; charset=utf-8', true)
            ->setHeader('Content-Security-Policy', "default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'", true);
        $store = $this->stores->getStore();
        $storeId = (int) $store->getId();
        if (!$this->request->isSecure() || !$this->config->enabled('agent_commerce/enabled', $storeId)) {
            return $response->setHttpResponseCode(404)->setContents('Shopping transfer unavailable.');
        }
        $ticket = (string) $this->request->getParam('ticket', '');
        if (preg_match('/^[a-f0-9]{64}$/D', $ticket) !== 1) {
            return $response->setHttpResponseCode(400)->setContents('Invalid transfer.');
        }
        if (!$this->request->isPost()) {
            return $response->setContents('<!doctype html><html><meta charset="utf-8"><title>Continue shopping</title><h1>'
                . $this->escape->escapeHtml($store->getName())
                . '</h1><p>Confirm to add the selected items to your existing cart after checking prices and stock.</p><form method="post"><input type="hidden" name="form_key" value="'
                . $this->escape->escapeHtmlAttr($this->formKey->getFormKey())
                . '"><input type="hidden" name="ticket" value="' . $this->escape->escapeHtmlAttr($ticket)
                . '"><button type="submit">Review and add to my cart</button></form></html>');
        }
        if (!$this->validator->validate($this->request)) {
            return $response->setHttpResponseCode(403)->setContents('Confirmation expired.');
        }
        $lock = 'selectika-transfer-' . hash('sha256', $this->session->getSessionId());
        if (!$this->locks->lock($lock, 0)) {
            return $response->setHttpResponseCode(409)->setContents('Another transfer is in progress.');
        }
        try {
            $origin = rtrim($this->config->value('agent_commerce/mcp_origin', $storeId), '/');
            $parts = parse_url($origin);
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment']) || isset($parts['port']) || !empty($parts['path'])) {
                throw new \RuntimeException('Invalid endpoint');
            }
            $host = $parts['host'] ?? '';
            $addresses = gethostbynamel($host);
            if (!$addresses) { throw new \RuntimeException('DNS failed'); }
            foreach ($addresses as $address) {
                if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new \RuntimeException('Private endpoint');
                }
            }
            $retailer = $this->config->value('connection/retailer_name', $storeId);
            $this->http->setTimeout(15);
            $this->http->setOption(CURLOPT_FOLLOWLOCATION, false);
            $this->http->setOption(CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            $this->http->setOption(CURLOPT_RESOLVE, [$host . ':443:' . $addresses[0]]);
            $this->http->addHeader('Content-Type', 'application/json');
            $this->http->addHeader('Authorization', 'Bearer ' . $this->config->agentToken($storeId));
            $this->http->post($origin . '/retailers/' . rawurlencode($retailer) . '/handoffs/redeem', json_encode(['ticket' => $ticket], JSON_THROW_ON_ERROR));
            if ($this->http->getStatus() !== 200) { throw new \RuntimeException('Ticket unavailable'); }
            $payload = json_decode($this->http->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if (($payload['retailer'] ?? '') !== $retailer || empty($payload['lines']) || count($payload['lines']) > 50) {
                throw new \RuntimeException('Invalid transfer');
            }
            $validated = [];
            foreach ($payload['lines'] as $line) {
                $qty = $line['quantity'] ?? null;
                if (!is_int($qty) || $qty < 1 || $qty > 99 || !is_string($line['variantId'] ?? null)) { throw new \RuntimeException('Invalid item'); }
                $resolved = $this->variants->resolve($line['variantId'], $qty, $storeId);
                [$product, $selected, $params] = $resolved;
                if (!$selected->isSalable()
                    || ($line['currency'] ?? '') !== $store->getCurrentCurrencyCode() || $store->getBaseCurrencyCode() !== $store->getCurrentCurrencyCode()
                    || abs((float) $selected->getFinalPrice($qty) - (float) ($line['price'] ?? -1)) > 0.00001) {
                    throw new \RuntimeException('Price or stock changed');
                }
                $validated[] = [$product, $params];
            }
            foreach ($validated as [$product, $params]) { $this->cart->addProduct($product, $params); }
            $this->cart->save();
            return $this->redirect->create()->setPath('checkout/cart');
        } catch (\Throwable $error) {
            // Never retry a redeemed ticket. Magento may have accepted some lines before a later failure.
            return $response->setHttpResponseCode(409)->setContents('Transfer needs attention. Inspect your store cart before asking for a new transfer. Price or stock may have changed; this link cannot be reused.');
        } finally { $this->locks->unlock($lock); }
    }
}
