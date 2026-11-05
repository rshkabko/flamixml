# Export: site → Bitrix24

`type=get_catalog&mode=query`, one step per request. The page lives in `$_SESSION['flamixml_page']`.

| Page | Response |
|---|---|
| 0 | `progress`, `Uploaded 0 of N products from the site`, FlamixML with categories and params |
| 1…ceil(N / items_per_page) | `progress`, `Uploaded X of N …`, FlamixML with one page of offers |
| after the last | `success`, `Uploaded N of N …`; the page resets to 0 |

Each step is also saved to `FLAMIX_LOGS_PATH/flamixml/export/import_<page>.xml` for debugging. `productCount()` returning `0` fails the step with `failure`, `Error: Products not found!`.

## Products callback

```php
class Products
{
    public static function productCount(): int
    {
        return 2;
    }

    // $page starts at 1.
    public static function get(int $page, array $params = []): array
    {
        return [[
            'id' => 'b5c3…',            // EXTERNAL_ID: Bitrix24 matches products by it
            'name' => 'Hoodie',
            'description' => '',        // null and '' fields are dropped
            'sku' => 'H-1',
            'image' => 'https://…/hoodie.jpg',
            'weight' => 100, 'length' => 12, 'width' => 34, 'height' => 56,
            'categories' => [['code' => 'id', 'value' => 'cat-hoodies']], // 'code' renames the tag, 'value' is its text
            'prices' => [
                'BASE' => ['code' => 'price', 'priceId' => 'BASE', 'currency' => 'UAH', 'priceItem' => 1002],
                'SALE' => ['code' => 'price', 'priceId' => 'SALE', 'currency' => 'UAH', 'priceItem' => 501],
            ],
        ]];
    }
}
```

`GetCatalog` maps these keys to FlamixML: `prices` → `prices/price`, `property_value` → `params/param/values/value`, `quantity` → `stocks/stock`. Other keys are not sent.

## Categories and attributes callbacks

```php
class Categories
{
    public static function get(int $page = 0, array $params = []): array
    {
        return [[
            'id' => 'cat-clothing',
            'name' => 'Clothing',
            'categories' => [['id' => 'cat-hoodies', 'name' => 'Hoodies']], // nested tree, any depth
        ]];
    }
}

class Attributes
{
    public static function get(int $page = 0, array $params = []): array
    {
        return [[
            'id' => 'pa_color',
            'name' => 'Color',
            'multi' => 'true',
            'variant_values' => [['code' => 'xml_value', 'value' => 'Red']],
        ]];
    }
}
```

## Result

FlamixML: page 0 holds `categories` and `params`, the next pages hold `offers`.

```xml
<flamixml_commerce version="1.0">
  <offers>
    <offer external_id="b5c3…">
      <name>Hoodie</name>
      <sku>H-1</sku>
      <active>true</active>
      <categories><category external_id="cat-hoodies"/></categories>
      <pictures><picture>https://…/hoodie.jpg</picture></pictures>
      <prices><price external_id="BASE" value="1002" currency="UAH"/></prices>
      <params>
        <param external_id="pa_color"><values><value>Red</value></values></param>
      </params>
    </offer>
  </offers>
</flamixml_commerce>
```
