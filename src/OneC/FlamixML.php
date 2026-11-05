<?php

namespace Flamix\FlamixML\OneC;

use SimpleXMLElement;
use XMLWriter;

/** Writes and reads FlamixML, the one catalog format of Flamix exchanges: page 0 is categories, warehouses and params, the next pages are offers. */
class FlamixML
{
    public const ROOT = 'flamixml_commerce';
    public const VERSION = '1.0';

    /**
     * @param array<int, array{xml_id: string, name: string, parent?: ?string}> $categories Parents before children.
     * @param array<int, array{xml_id: string, name: string, type: string, multiple: bool, values?: array}> $properties
     * @param array<int, array{xml_id: string, name: string}> $warehouses
     */
    public static function classifier(array $categories, array $properties, array $warehouses = []): string
    {
        $xml = self::open();

        if ($categories) {
            $xml->startElement('categories');
            foreach ($categories as $category) {
                $xml->startElement('category');
                self::attributes($xml, ['external_id' => $category['xml_id'], 'parent_external_id' => $category['parent'] ?? '']);
                self::element($xml, 'name', $category['name']);
                $xml->endElement();
            }
            $xml->endElement();
        }

        if ($warehouses) {
            $xml->startElement('warehouses');
            foreach ($warehouses as $warehouse) {
                $xml->startElement('warehouse');
                self::attributes($xml, ['external_id' => $warehouse['xml_id']]);
                self::element($xml, 'name', $warehouse['name']);
                $xml->endElement();
            }
            $xml->endElement();
        }

        if ($properties) {
            $xml->startElement('params');
            foreach ($properties as $property) {
                $xml->startElement('param');
                self::attributes($xml, [
                    'external_id' => $property['xml_id'],
                    'type' => $property['type'] === 'string' ? '' : $property['type'],
                    'multiple' => $property['multiple'] ? 'true' : '',
                ]);
                self::element($xml, 'name', $property['name']);
                self::values($xml, array_map(function ($value) {
                    return ['value_id' => $value['xml_id'], 'value' => $value['value']];
                }, $property['values'] ?? []));
                $xml->endElement();
            }
            $xml->endElement();
        }

        return self::close($xml);
    }

    /** @param array<int, array> $products Rows of the readProducts() shape. */
    public static function offers(array $products): string
    {
        $xml = self::open();
        $xml->startElement('offers');
        foreach ($products as $product)
            self::offer($xml, $product);
        $xml->endElement();

        return self::close($xml);
    }

    /**
     * @return array<int, array{xml_id: string, name: string, parent: ?string}>
     * @throws \Exception
     */
    public static function readCategories(string $content): array
    {
        $result = [];
        foreach (self::parse($content)->categories->category ?? [] as $category) {
            $result[] = [
                'xml_id' => (string)$category['external_id'],
                'name' => (string)$category->name,
                'parent' => (string)$category['parent_external_id'] ?: null,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{xml_id: string, name: string}>
     * @throws \Exception
     */
    public static function readWarehouses(string $content): array
    {
        $result = [];
        foreach (self::parse($content)->warehouses->warehouse ?? [] as $warehouse) {
            $result[] = ['xml_id' => (string)$warehouse['external_id'], 'name' => (string)$warehouse->name];
        }

        return $result;
    }

    /**
     * @return array<int, array{xml_id: string, name: string, type: string, multiple: bool, values: array}>
     * @throws \Exception
     */
    public static function readProperties(string $content): array
    {
        $result = [];
        foreach (self::parse($content)->params->param ?? [] as $param) {
            $values = [];
            foreach ($param->values->value ?? [] as $value) {
                $values[] = ['xml_id' => (string)$value['external_id'], 'value' => (string)$value];
            }

            $result[] = [
                'xml_id' => (string)$param['external_id'],
                'name' => (string)$param->name,
                'type' => (string)$param['type'] ?: 'string',
                'multiple' => (string)$param['multiple'] === 'true',
                'values' => $values,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{xml_id: string, parent_id: ?string, active: bool, name: string, sku: string, description: string, categories: array, images: array, properties: array, prices: array, stocks: array}>
     * @throws \Exception
     */
    public static function readProducts(string $content): array
    {
        $result = [];
        foreach (self::parse($content)->offers->offer ?? [] as $offer) {
            $properties = $prices = $stocks = $categories = $images = [];
            foreach ($offer->params->param ?? [] as $param) {
                foreach ($param->values->value ?? [] as $value) {
                    $row = ['code' => (string)$param['external_id'], 'value' => (string)$value];
                    if ((string)$value['external_id'] !== '')
                        $row['value_id'] = (string)$value['external_id'];
                    $properties[] = $row;
                }
            }
            foreach ($offer->prices->price ?? [] as $price) {
                $prices[] = ['type' => (string)$price['external_id'], 'currency' => (string)$price['currency'], 'value' => (string)$price['value']];
            }
            foreach ($offer->stocks->stock ?? [] as $stock) {
                $stocks[] = ['warehouse' => (string)$stock['external_id'], 'value' => (string)$stock['value']];
            }
            foreach ($offer->categories->category ?? [] as $category) {
                $categories[] = (string)$category['external_id'];
            }
            foreach ($offer->pictures->picture ?? [] as $picture) {
                $images[] = (string)$picture;
            }

            $result[] = [
                'xml_id' => (string)$offer['external_id'],
                'parent_id' => (string)$offer['parent_external_id'] ?: null,
                'active' => (string)$offer->active !== 'false',
                'name' => (string)$offer->name,
                'sku' => (string)$offer->sku,
                'description' => (string)$offer->description,
                'categories' => $categories,
                'images' => $images,
                'properties' => $properties,
                'prices' => $prices,
                'stocks' => $stocks,
            ];
        }

        return $result;
    }

    private static function offer(XMLWriter $xml, array $product): void
    {
        $xml->startElement('offer');
        self::attributes($xml, ['external_id' => $product['xml_id'], 'parent_external_id' => $product['parent_id'] ?? '']);
        self::element($xml, 'name', $product['name']);
        self::element($xml, 'sku', $product['sku'] ?? '');
        self::element($xml, 'active', $product['active'] ? 'true' : 'false');
        self::element($xml, 'description', $product['description'] ?? '');

        self::records($xml, 'categories', 'category', array_map(function ($id) {
            return ['external_id' => $id];
        }, $product['categories'] ?? []));

        if (!empty($product['images'])) {
            $xml->startElement('pictures');
            foreach ($product['images'] as $image)
                self::element($xml, 'picture', $image);
            $xml->endElement();
        }

        self::records($xml, 'prices', 'price', array_map(function ($price) {
            return ['external_id' => $price['type'], 'value' => $price['value'], 'currency' => $price['currency']];
        }, $product['prices'] ?? []));

        self::records($xml, 'stocks', 'stock', array_map(function ($stock) {
            return ['external_id' => $stock['warehouse'] ?? '', 'value' => $stock['value']];
        }, $product['stocks'] ?? []));

        $byCode = [];
        foreach ($product['properties'] ?? [] as $property)
            $byCode[$property['code']][] = $property;

        if ($byCode) {
            $xml->startElement('params');
            foreach ($byCode as $code => $values) {
                $xml->startElement('param');
                self::attributes($xml, ['external_id' => $code]);
                self::values($xml, $values);
                $xml->endElement();
            }
            $xml->endElement();
        }

        $xml->endElement();
    }

    private static function open(): XMLWriter
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement(self::ROOT);
        $xml->writeAttribute('version', self::VERSION);

        return $xml;
    }

    private static function close(XMLWriter $xml): string
    {
        // endDocument closes every element still open.
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /** @throws \Exception */
    private static function parse(string $content): SimpleXMLElement
    {
        $xml = simplexml_load_string($content);
        if ($xml === false)
            throw new \Exception('FlamixML: broken XML');

        return $xml;
    }

    private static function records(XMLWriter $xml, string $name, string $item, array $rows): void
    {
        if (!$rows)
            return;

        $xml->startElement($name);
        foreach ($rows as $row) {
            $xml->startElement($item);
            self::attributes($xml, $row);
            $xml->endElement();
        }
        $xml->endElement();
    }

    private static function values(XMLWriter $xml, array $values): void
    {
        if (!$values)
            return;

        $xml->startElement('values');
        foreach ($values as $value) {
            $xml->startElement('value');
            self::attributes($xml, ['external_id' => $value['value_id'] ?? '']);
            $xml->text(self::clean($value['value']));
            $xml->endElement();
        }
        $xml->endElement();
    }

    private static function element(XMLWriter $xml, string $name, $value): void
    {
        if ((string)$value !== '')
            $xml->writeElement($name, self::clean($value));
    }

    private static function attributes(XMLWriter $xml, array $attributes): void
    {
        foreach ($attributes as $name => $value) {
            if ((string)$value !== '')
                $xml->writeAttribute($name, self::clean($value));
        }
    }

    // Control characters from a CMS description make the whole page unparsable on the receiving side.
    private static function clean($value): string
    {
        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub((string)$value, 'UTF-8'));
    }
}
