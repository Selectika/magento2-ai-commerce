<?php
declare(strict_types=1);
namespace Selectika\AiCommerce\Model;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Lock\LockManagerInterface;

class AgentCart {
    public function __construct(private Config $config, private StoreManagerInterface $stores, private QuoteFactory $quotes, private QuoteIdMaskFactory $masks, private CartRepositoryInterface $repository, private AgentVariant $variants, private LockManagerInterface $locks) {}
    public function authorized(RequestInterface $request): bool {
        $storeId = (int) $this->stores->getStore()->getId();
        $secret = $this->config->agentCartToken($storeId);
        return $request->isSecure() && $this->config->enabled('agent_commerce/enabled', $storeId) && strlen($secret) >= 32 && hash_equals('Bearer ' . $secret, (string) $request->getHeader('Authorization'));
    }
    public function dispatch(string $action, array $input): array {
        $store = $this->stores->getStore();
        if ($action === 'create') {
            if ($input) { throw new \InvalidArgumentException('Unexpected input'); }
            $quote = $this->quotes->create()->setStore($store)->setIsActive(true)->setCustomerIsGuest(true);
            $this->repository->save($quote);
            $mask = $this->masks->create()->setQuoteId($quote->getId()); $mask->save();
            return ['reference' => (string) $mask->getMaskedId()];
        }
        if (array_diff(array_keys($input), $action === 'replace' ? ['reference', 'lines'] : ['reference']) || !is_string($input['reference'] ?? null) || preg_match('/^[a-zA-Z0-9]{16,128}$/D', $input['reference']) !== 1) { throw new \InvalidArgumentException('Invalid reference'); }
        $lock = 'selectika-guest-' . hash('sha256', $input['reference']);
        if (!$this->locks->lock($lock, 0)) { throw new \RuntimeException('Concurrent request'); }
        try {
            $mask = $this->masks->create()->load($input['reference'], 'masked_id');
            $quote = $this->repository->getActive((int) $mask->getQuoteId());
            if ((int) $quote->getStoreId() !== (int) $store->getId() || $quote->getCustomerId() || !$quote->getCustomerIsGuest()) { throw new \RuntimeException('Wrong cart scope'); }
            if ($action === 'replace') {
                if (!is_array($input['lines'] ?? null) || count($input['lines']) > 50) { throw new \InvalidArgumentException('Invalid lines'); }
                $resolved = []; $seen = [];
                foreach ($input['lines'] as $line) {
                    if (!is_array($line) || array_diff(array_keys($line), ['variantId', 'quantity']) || !is_string($line['variantId'] ?? null) || !is_int($line['quantity'] ?? null) || isset($seen[$line['variantId']])) { throw new \InvalidArgumentException('Invalid variant'); }
                    $seen[$line['variantId']] = true;
                    $resolved[] = [$line['variantId'], $this->variants->resolve($line['variantId'], $line['quantity'], (int) $store->getId())];
                }
                $quote->removeAllItems();
                foreach ($resolved as [$id, [$product, $selected, $params]]) {
                    if (!$selected->isSalable()) { throw new \RuntimeException('Out of stock'); }
                    $item = $quote->addProduct($product, new DataObject($params));
                    if (is_string($item)) { throw new \RuntimeException('Variant rejected'); }
                    $item->addOption(['code' => 'selectika_agent_variant', 'value' => $id, 'product_id' => $product->getId()]);
                }
            }
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            if ($quote->getHasError()) { throw new \RuntimeException('Cart validation failed'); }
            $lines = [];
            foreach ($quote->getAllVisibleItems() as $item) {
                $tag = $item->getOptionByCode('selectika_agent_variant');
                if (!$tag || $item->getHasError()) { throw new \RuntimeException('Unverified cart item'); }
                $item->checkData();
                $lines[] = ['variantId' => (string) $tag->getValue(), 'quantity' => (int) $item->getQty(), 'price' => (string) $item->getCalculationPrice(), 'currency' => (string) $quote->getQuoteCurrencyCode(), 'available' => !$item->getHasError() && $item->getProduct()->isSalable()];
            }
            if ($action === 'replace') { $this->repository->save($quote); }
            return ['lines' => $lines, 'observedAt' => gmdate('Y-m-d\TH:i:s\Z')];
        } finally { $this->locks->unlock($lock); }
    }
}
