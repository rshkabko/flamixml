# Import: Bitrix24 → site

FlamixML only. Bitrix24 uploads `offers.xml` (or a `.zip` of it) with `mode=file`, then calls `mode=import` until it gets `success`.

| `mode=import` call | Response |
|---|---|
| a `.zip` is in `import/` | extracts its `.xml` entries only and deletes it: `progress`, `Extracting zip file offers.zip` |
| an `.xml` is in `import/` | reads it with `FlamixML::readProducts()`, calls the handlers per offer, deletes it: `progress`, `Import and delete file offers.xml` |
| nothing left | `success`, `All files was imported!` |

An XML without offers, a CommerceML file included, fails with `failure`, `Error: Empty offers in file: …`.

## Handlers

Override them in the [exchange class](setup.md). Each gets the offer `external_id` (your EXTERNAL_ID) and its part of the offer, and is skipped when that part is empty. The default returns `false` and does nothing. All values are strings. One row comes as a row, several as a list.

```php
public static function restsHandler($product_id, array $rests): bool
{
    // <stock external_id="main" value="7"/> → ['id' => 'main', 'quantity' => '7']
    // several stocks → [['id' => 'main', 'quantity' => '3'], ['id' => 'reserve', 'quantity' => '4']]
    $quantity = $rests['quantity'] ?? array_sum(array_column($rests, 'quantity'));
    return Products::set(Products::findByExternalId($product_id), ['quantity' => $quantity]);
}

public static function pricesHandler($product_id, array $prices): bool
{
    // <price external_id="BASE" value="1002" currency="UAH"/> → ['type' => 'BASE', 'currency' => 'UAH', 'value' => '1002']
    return false;
}

public static function productsHandler($product_id, array $products): bool
{
    // <name>, <sku>, <description>, when any is set → ['id' => $product_id, 'name' => …, 'sku' => …, 'description' => …]
    return false;
}
```

## Offers file

```xml
<flamixml_commerce version="1.0">
  <offers>
    <offer external_id="b5c3…">
      <prices><price external_id="BASE" value="1002" currency="UAH"/></prices>
      <stocks><stock external_id="main" value="7"/></stocks>
    </offer>
  </offers>
</flamixml_commerce>
```
