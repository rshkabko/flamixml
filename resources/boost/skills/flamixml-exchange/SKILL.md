---
name: flamixml-exchange
description: "Flamix FlamixML exchange protocol and XML. Use when writing or debugging a site exchange class extending Flamix\\FlamixML\\Init (actionCheck, restsHandler, pricesHandler, productsHandler, product/category/attribute callbacks, productCount), the ?type=catalog|get_catalog&mode=checkauth|query|file|import|init requests, config.php keys (items_per_page, dir_import), or writing/reading XML with OneC\\FlamixML (classifier, offers, readProducts). Also use for the symptoms: mode=import answers the same failure forever, 'Session did not pass checkauth!' mid-exchange, an upload rejected with 'Only .xml and .zip files are accepted!', an export that fails with \"Products not found!\", code after a response never runs. Skip for the Inventory app's own 1C controllers and Bitrix24 REST calls."
---

# FlamixML exchange

One URL on the site answers Bitrix24 step by step: `checkauth` issues a PHP session, `query` exports the catalog in FlamixML one page per request, `file` + `import` bring stock back, also in FlamixML. `OneC\FlamixML` writes and reads the format.

Full docs: vendor/flamix/flamixml/docs/setup.md, export.md, import.md, xml.md. Below is what the docs get wrong or omit.

## Every response ends the process

`flamixml_response()` calls `die()`. `GetCatalog::query()`, `Files::uploadBinary()` and both `Import` loops print and die inside, so:

- one request = one step: one zip extracted, or one XML imported, or one export page;
- code after `static::exchange()` or after a handler loop never runs; do cleanup inside the handler;
- PHPUnit cannot call these in-process. The package tests run a real `php -S` against `tests/Fixtures/server.php`.

## Import traps

- One row reaches a handler as a row, several as a list: `$rests['quantity'] ?? array_sum(array_column($rests, 'quantity'))`.
- Handlers written for `flamix/commerceml` read Russian price keys: here they would silently write nothing, read `type`, `currency`, `value`.
- A CommerceML file has no offers for this package: `Empty offers in file`.
- A file whose offers are empty throws before `deleteFile()`: it stays in `import/`, and every next `mode=import` answers the same `failure`. Clear it with `mode=init`.
- `Files::create()` truncates before `uploadBinary()` appends: a file sent in several `mode=file` parts keeps only the last part.
- `?filename=` is reduced to its base name and must match `*.xml` or `*.zip`; a zip yields only its `.xml` entries, flattened.
- Every mode needs a session from checkauth: a pause over `session.gc_maxlifetime` between steps fails with `Session did not pass checkauth!`; call checkauth again.
- `mode=init` clears the hardcoded `export/` or `import/`, not `dir_export`/`dir_import` from config.php.

## Export traps

- `productCount()` is required on the product callback though `ExportImport` does not declare it. Returning `0` fails the step: `failure`, `Error: Products not found!`.
- Products `get($page)` is called with pages starting at 1; page 0 is the classifier.
- `null` and `''` fields are dropped; `0` and `false` are kept.
- The XML Bitrix24 reads is the response body. `export/import_<page>.xml` is only a debug copy.

## Boot traps

- `Init::init($dir)` calls `define('FLAMIXML_EXCHANGE_DIR_PATH')`; a second call warns and keeps the first path.
- `Files::exchange()` throws without `FLAMIX_LOGS_PATH`: call `\Flamix\Plugin\Init::init(...)->setLogsPath(...)` first.
- `flamixml_config('key')` with no default returns the whole config array when the key is missing, not `null`.
- The parent `actionCheck()` always throws; checkauth fails until you override it.
- Old snippets use `\Flamix\Sync\Init::init()`; the class is `\Flamix\FlamixML\Init`.

## Building XML in an app

```php
use Flamix\FlamixML\OneC\FlamixML;

$xml = FlamixML::offers([['xml_id' => $id, 'active' => true, 'name' => '', 'stocks' => [['warehouse' => 'main', 'value' => '7']]]]);

foreach (FlamixML::readProducts($xml) as $offer) { /* lists are always lists */ }
```
