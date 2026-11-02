<?php
declare(strict_types=1);
namespace Selectika\AiCommerce\Model;
use Magento\Catalog\Api\ProductRepositoryInterface;

/** Resolves the same product#attribute#option identifiers used by existing storefront widgets. */
class AgentVariant {
    public function __construct(private ProductRepositoryInterface $products) {}
    public function resolve(string $id, int $quantity, int $storeId): array {
        if ($quantity < 1 || $quantity > 99 || strlen($id) > 200 || $id === '') { throw new \InvalidArgumentException('Invalid variant'); }
        if (str_contains($id, '#')) {
            if (preg_match('/^[1-9][0-9]*(?:#[1-9][0-9]*#[1-9][0-9]*)+$/D', $id) !== 1) { throw new \InvalidArgumentException('Invalid configurable variant'); }
            $parts = explode('#', $id);
            $product = $this->products->getById((int) array_shift($parts), false, $storeId, true);
            if ($product->getTypeId() !== 'configurable') { throw new \InvalidArgumentException('Not configurable'); }
            $attributes = [];
            for ($i = 0; $i < count($parts); $i += 2) {
                if (isset($attributes[$parts[$i]])) { throw new \InvalidArgumentException('Duplicate attribute'); }
                $attributes[$parts[$i]] = $parts[$i + 1];
            }
            $required = array_map(static fn($attribute) => (string) $attribute['attribute_id'], $product->getTypeInstance()->getConfigurableAttributesAsArray($product));
            if (count($required) !== count($attributes) || array_diff($required, array_keys($attributes))) { throw new \InvalidArgumentException('Incomplete variant'); }
            $selected = $product->getTypeInstance()->getProductByAttributes($attributes, $product);
            if (!$selected || !$selected->getId()) { throw new \InvalidArgumentException('Variant unavailable'); }
            return [$product, $selected, ['qty' => $quantity, 'super_attribute' => $attributes]];
        }
        $product = ctype_digit($id) ? $this->products->getById((int) $id, false, $storeId, true) : $this->products->get($id, false, $storeId, true);
        if ($product->getTypeId() !== 'simple') { throw new \InvalidArgumentException('Exact variant required'); }
        return [$product, $product, ['qty' => $quantity]];
    }
}
