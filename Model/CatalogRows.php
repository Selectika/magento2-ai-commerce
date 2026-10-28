<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Model;

use Generator;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use RuntimeException;

/** One row per child SKU; parent identity is never replaced with a variant or cart token. */
class CatalogRows
{
    private array $categoryNames = [];

    public function __construct(
        private CollectionFactory $collections,
        private Configurable $configurable,
        private CategoryRepositoryInterface $categories,
        private Config $config
    ) {
    }

    /** @return Generator<array<string, mixed>> */
    public function rows(StoreInterface $store): Generator
    {
        $this->categoryNames = [];
        $lastId = 0;
        do {
            $collection = $this->collections->create();
            $collection->setStoreId((int) $store->getId())->addStoreFilter((int) $store->getId())
                ->addAttributeToSelect('*')
                ->addAttributeToFilter('status', Status::STATUS_ENABLED)
                ->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE])
                ->addFieldToFilter('entity_id', ['gt' => $lastId])
                ->setOrder('entity_id', 'ASC')->setPageSize(100)->setCurPage(1);
            $collection->load()->addMediaGalleryData();
            $loaded = count($collection);
            foreach ($collection as $parent) {
                $lastId = (int) $parent->getId();
                if (!in_array($parent->getTypeId(), ['simple', 'configurable', 'virtual', 'downloadable'], true)) {
                    throw new RuntimeException('Catalog contains a product type not supported by this connector version.');
                }
                if ($parent->getTypeId() === 'configurable') {
                    $children = $this->configurable->getUsedProducts($parent);
                    if ($children === []) {
                        throw new RuntimeException('A configurable product has no exportable variants.');
                    }
                    foreach ($children as $child) {
                        yield $this->row($parent, $child, $store);
                    }
                } else {
                    yield $this->row($parent, $parent, $store);
                }
            }
            $collection->clear();
        } while ($loaded === 100);
    }

    private function row(Product $parent, Product $variant, StoreInterface $store): array
    {
        $storeId = (int) $store->getId();
        $colorCode = $this->config->attributeCode('color_attribute', $storeId, 'color');
        $sizeCode = $this->config->attributeCode('size_attribute', $storeId, 'size');
        $images = $this->images($variant, $store);
        if ($images === []) {
            $images = $this->images($parent, $store);
        }
        $attributes = [];
        foreach ($this->config->extraAttributes($storeId) as $code) {
            $attributes[$code] = $this->attributeLabel($variant, $code) ?: $this->attributeLabel($parent, $code);
        }
        $regularPrice = (float) $variant->getPrice();
        $finalPrice = (float) $variant->getFinalPrice();
        return [
            'parent_sku' => (string) $parent->getSku(),
            'parent_identifier' => (string) $parent->getId(),
            'sku' => (string) $variant->getSku(),
            'id' => (string) $variant->getId(),
            'magento_product_type' => (string) $parent->getTypeId(),
            'store_code' => (string) $store->getCode(),
            'currency' => (string) $store->getBaseCurrencyCode(),
            'title' => (string) $parent->getName(),
            'description' => (string) $parent->getData('description'),
            'link' => (string) $parent->getProductUrl(),
            'image_urls' => json_encode($images, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            // The current generic JSON converter expects strings for currency parsing.
            'price' => (string) $regularPrice,
            'sale_price' => (string) $finalPrice,
            'availability' => (int) $variant->getStatus() === Status::STATUS_ENABLED && $variant->isSalable() ? 'in' : 'out',
            // eIdMerge groups by color before deduplicating parent IDs. Keep that
            // grouping stable, otherwise later colors of one parent would be lost.
            'color' => $this->attributeLabel($parent, $colorCode),
            'variant_color' => $this->attributeLabel($variant, $colorCode),
            'size' => $this->attributeLabel($variant, $sizeCode) ?: 'One size',
            'color_id' => (string) $variant->getData($colorCode),
            'size_id' => (string) $variant->getData($sizeCode),
            'category_path' => $this->categoryPath($parent, $storeId),
            'brand' => $attributes['manufacturer'] ?? '',
            'material' => $attributes['material'] ?? '',
            'attributes' => $attributes,
        ];
    }

    private function images(Product $product, StoreInterface $store): array
    {
        $base = rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA, true), '/') . '/catalog/product';
        $images = [];
        $main = (string) $product->getData('image');
        if ($main !== '' && $main !== 'no_selection') {
            $images[] = $base . '/' . ltrim($main, '/');
        }
        $gallery = $product->getMediaGalleryImages();
        if ($gallery) {
            foreach ($gallery as $image) {
                if (!$image->getData('disabled')) {
                    $images[] = $base . '/' . ltrim((string) $image->getFile(), '/');
                }
            }
        }
        return array_slice(array_values(array_unique($images)), 0, 20);
    }

    private function attributeLabel(Product $product, string $code): string
    {
        $value = $product->getAttributeText($code);
        if ($value === false || $value === null || $value === '') {
            $value = $product->getData($code);
        }
        return is_array($value) ? implode(', ', $value) : (is_scalar($value) ? (string) $value : '');
    }

    private function categoryPath(Product $product, int $storeId): string
    {
        $paths = [];
        foreach ($product->getCategoryIds() as $id) {
            $key = $storeId . ':' . $id;
            if (!isset($this->categoryNames[$key])) {
                $category = $this->categories->get((int) $id, $storeId);
                $names = [];
                foreach (explode('/', (string) $category->getPath()) as $ancestorId) {
                    $ancestor = $this->categories->get((int) $ancestorId, $storeId);
                    if ((int) $ancestor->getLevel() > 1) {
                        $names[] = (string) $ancestor->getName();
                    }
                }
                $this->categoryNames[$key] = implode(' > ', $names);
            }
            $paths[] = $this->categoryNames[$key];
        }
        return implode(' | ', array_values(array_unique(array_filter($paths))));
    }
}
