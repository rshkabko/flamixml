<?php

namespace Flamix\FlamixML\Operations;

use Flamix\FlamixML\OneC\FlamixML;
use Flamix\FlamixML\Operations\Traits\SessionPaginator;

/** The get_catalog export in FlamixML: page 0 is categories and params, pages 1..N are offers, the session holds the page. */
class GetCatalog
{
    use SessionPaginator;

    /**
     * Prints the current step as progress, or success and a reset to page 0 after the last one.
     *
     * @throws \Exception
     */
    public function query(string $product_callback, string $category_callback, string $attribute_callback)
    {
        $this->setElementsCount(call_user_func([$product_callback, 'productCount']));
        $description = 'Uploaded ' . $this->currentElement() . ' of ' . $this->getElementsCount() . ' products from the site';
        flamixml_log($description, [
            'current_page' => $this->currentPage(),
            'max_page' => $this->calculatePage(),
            'next_page' => $this->getNextPage()
        ]);

        if ($this->isStart()) {
            $this->savePreparedAndSwitchPage($this->step_zero($category_callback, $attribute_callback), $description);
        } else if (!$this->isFinish()) {
            $this->savePreparedAndSwitchPage($this->step_products($product_callback), $description);
        }

        $this->finish();
    }

    /** Page 0: categories and param definitions. */
    protected function step_zero(string $category_callback, string $attribute_callback): string
    {
        return FlamixML::classifier($this->categories(call_user_func([$category_callback, 'get'])), array_map(fn(array $attribute) => [
            'xml_id' => (string)$attribute['id'],
            'name' => (string)($attribute['name'] ?? $attribute['id']),
            'type' => 'string',
            'multiple' => filter_var($attribute['multi'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], array_values(call_user_func([$attribute_callback, 'get']))));
    }

    /** One page of offers, items_per_page long. */
    protected function step_products(string $product_callback): string
    {
        return FlamixML::offers(array_map([$this, 'offer'], array_values(call_user_func([$product_callback, 'get'], $this->currentPage()))));
    }

    /** The nested category tree of the callback as a flat parents-first list. */
    private function categories(array $tree, ?string $parent = null): array
    {
        $result = [];
        foreach ($tree as $category) {
            $result[] = ['xml_id' => (string)$category['id'], 'name' => (string)($category['name'] ?? ''), 'parent' => $parent];
            $result = array_merge($result, $this->categories($category['categories'] ?? [], (string)$category['id']));
        }

        return $result;
    }

    /** A product of the callback, see docs/export.md, as a FlamixML row. */
    private function offer(array $product): array
    {
        $properties = [];
        // OpenCart names the same [attribute id => text] map "attributes".
        foreach ($product['property_value'] ?? $product['attributes'] ?? [] as $code => $values) {
            foreach ((array)$values as $value) {
                if ((string)$value !== '')
                    $properties[] = ['code' => (string)$code, 'value' => (string)$value];
            }
        }

        $prices = [];
        foreach ($product['prices'] ?? [] as $price) {
            if ((string)($price['priceItem'] ?? '') !== '')
                $prices[] = ['type' => (string)($price['priceId'] ?? ''), 'value' => $this->number($price['priceItem']), 'currency' => (string)($price['currency'] ?? '')];
        }

        return [
            'xml_id' => (string)($product['id'] ?? ''),
            'parent_id' => $product['parent_id'] ?? null,
            'active' => empty($product['deleted']),
            'name' => (string)($product['name'] ?? ''),
            'sku' => (string)($product['sku'] ?? ''),
            'description' => (string)($product['description'] ?? ''),
            'categories' => array_map(fn($category) => (string)(is_array($category) ? ($category['value'] ?? '') : $category), $product['categories'] ?? []),
            'images' => array_filter((array)($product['image'] ?? []), fn($image) => (string)$image !== ''),
            'properties' => $properties,
            'prices' => $prices,
            'stocks' => (string)($product['quantity'] ?? '') === '' ? [] : [['value' => $this->number($product['quantity'])]],
        ];
    }

    // PHP 7 prints a float by the site locale: 100,5 on a uk_UA WordPress.
    private function number($value): string
    {
        return str_replace(',', '.', (string)$value);
    }

    protected function nextPageProgress(string $description)
    {
        $this->setNextPage();
        flamixml_response_by_type('progress', $description);
    }

    protected function finish()
    {
        $this->setPage(0);
        flamixml_response_by_type('success', 'Uploaded ' . $this->getElementsCount() . ' of ' . $this->getElementsCount() . ' products from the site');
    }

    /**
     * Saves export/import_<page>.xml and prints it as progress.
     *
     * @throws \Exception
     */
    protected function savePreparedAndSwitchPage(string $xml_content, $description)
    {
        $exportFile = Files::exchange(flamixml_config('dir_export', 'export'))->getPath('import_' . $this->currentPage() . '.xml');
        // A copy for debugging only: the app reads the XML from the response body, not this file.
        file_put_contents($exportFile, $xml_content);
        $this->nextPageProgress($description . PHP_EOL . $xml_content);
    }
}
