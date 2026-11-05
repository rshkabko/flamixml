# FlamixML XML and helpers

`Flamix\FlamixML\OneC\FlamixML` writes and reads the XML; usable on its own, without the exchange. Export examples: `etalons/import_0.xml` (page 0: categories, warehouses, params), `etalons/import_1.xml` and `import_2.xml` (offers), `etalons/expected/` (the same catalog merged and repaged by the app).

## Build

```php
use Flamix\FlamixML\OneC\FlamixML;

$page0 = FlamixML::classifier(
    [['xml_id' => 'cat-1', 'name' => 'Clothing', 'parent' => null]],
    [['xml_id' => 'pa_color', 'name' => 'Color', 'type' => 'string', 'multiple' => true]],
    [['xml_id' => 'main', 'name' => 'Main']]
);

$page1 = FlamixML::offers([[
    'xml_id' => 'b5c3…',
    'active' => true,
    'name' => 'Hoodie',
    'prices' => [['type' => 'BASE', 'value' => '1002', 'currency' => 'UAH']],
    'stocks' => [['warehouse' => 'main', 'value' => '7']],
]]);
```

Empty values are omitted.

## Parse

```php
FlamixML::readCategories($page0); // xml_id, name, parent
FlamixML::readWarehouses($page0); // xml_id, name
FlamixML::readProperties($page0); // xml_id, name, type, multiple, values
FlamixML::readProducts($page1);   // xml_id, parent_id, active, name, sku, description, categories, images, properties, prices, stocks
```

Broken XML throws. Lists are always lists, also for one item.

## Helpers

Defined by `\Flamix\FlamixML\Init::init()`, not by the Composer autoloader.

```php
flamixml_log('Import started', ['file' => 'offers.xml']); // FLAMIX_LOGS_PATH/logs/flamixml/<date>-info-….log

flamixml_config('items_per_page', 30); // key from FLAMIXML_EXCHANGE_DIR_PATH/config.php, or the default
flamixml_config('missing');            // null default + missing key = the whole config array

flamixml_response(['success', 'PHPSESSID', $id]); // echoes the lines joined by PHP_EOL, then die()

flamixml_response_by_type('progress', 'Step 2'); // sends response_header, prints "progress\nStep 2", die()
flamixml_response_by_type('oops', 'x');          // anything but success/progress prints "failure"
```
