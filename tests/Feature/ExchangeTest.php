<?php

namespace Flamix\FlamixML\Tests\Feature;

use Composer\Autoload\ClassLoader;
use Flamix\FlamixML\OneC\FlamixML;
use PHPUnit\Framework\TestCase;

/** The exchange as Bitrix24 drives it: HTTP requests against a real `php -S` running Shop::start(). */
class ExchangeTest extends TestCase
{
    private static $server;
    private static string $url;
    private static string $workdir;
    private ?string $cookie = null;

    public static function setUpBeforeClass(): void
    {
        self::$workdir = sys_get_temp_dir() . '/commerceml-test-' . getmypid();
        @mkdir(self::$workdir, 0755, true);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$url = 'http://127.0.0.1:' . $port . '/';

        // Not ReflectionClass(ClassLoader): Herd prepends a phar with its own copy of that class.
        $vendor = key(array_filter(ClassLoader::getRegisteredLoaders(), fn($loader) => $loader->findFile(\Flamix\FlamixML\Init::class)));
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/Fixtures/server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            self::$workdir,
            ['COMMERCEML_AUTOLOAD' => $vendor . '/autoload.php', 'COMMERCEML_WORKDIR' => self::$workdir]
        );

        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++)
            usleep(100000);
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        exec('rm -rf ' . escapeshellarg(self::$workdir));
    }

    protected function setUp(): void
    {
        exec('rm -rf ' . escapeshellarg(self::$workdir . '/flamixml') . ' ' . escapeshellarg(self::$workdir . '/calls.json'));
    }

    public function test_checkauth_returns_a_session_for_valid_credentials()
    {
        $lines = $this->login();

        $this->assertSame('success', $lines[0]);
        $this->assertSame('PHPSESSID', $lines[1]);
        $this->assertSame($lines[2], $this->cookie);
    }

    public function test_checkauth_fails_on_a_wrong_password()
    {
        $this->assertSame(['failure', 'Error: Bad password!'], $this->call(['type' => 'catalog', 'mode' => 'checkauth'], null, 'login:wrong'));
    }

    public function test_an_unknown_type_is_rejected_before_auth()
    {
        $this->assertSame(['failure', 'Type must be catalog or get_catalog, sale given!'], $this->call(['type' => 'sale', 'mode' => 'checkauth']));
    }

    public function test_query_without_a_session_cookie_fails()
    {
        $this->assertSame(['failure', 'Empty PHPSESSID in Cookie!'], $this->call(['type' => 'get_catalog', 'mode' => 'query']));
    }

    public function test_mode_requires_a_session_from_checkauth()
    {
        $this->cookie = 'session0without0checkauth';

        $this->assertSame(['failure', 'Session did not pass checkauth!'], $this->call(['type' => 'get_catalog', 'mode' => 'query']));
    }

    public function test_query_exports_the_classifier_then_one_products_page_per_request()
    {
        $this->login();

        $step0 = $this->call(['type' => 'get_catalog', 'mode' => 'query']);
        $this->assertSame('progress', $step0[0]);
        $this->assertSame('Uploaded 0 of 2 products from the site', $step0[1]);
        $classifier = $this->xml($step0);
        $this->assertSame('flamixml_commerce', $classifier->getName());
        $this->assertSame('cat-clothing', (string)$classifier->categories->category[0]['external_id']);
        $this->assertSame('Hoodies', (string)$classifier->categories->category[1]->name);
        $this->assertSame('cat-clothing', (string)$classifier->categories->category[1]['parent_external_id']);
        $this->assertSame('pa_color', (string)$classifier->params->param['external_id']);
        $this->assertSame('Color', (string)$classifier->params->param->name);
        $this->assertSame('true', (string)$classifier->params->param['multiple']);

        $step1 = $this->call(['type' => 'get_catalog', 'mode' => 'query']);
        $this->assertSame('Uploaded 1 of 2 products from the site', $step1[1]);
        $offer = $this->xml($step1)->offers->offer;
        $this->assertSame('product-1', (string)$offer['external_id']);
        $this->assertSame('SKU-1', (string)$offer->sku);
        $this->assertSame('true', (string)$offer->active);
        $this->assertSame('cat-hoodies', (string)$offer->categories->category['external_id']);
        $this->assertSame('BASE', (string)$offer->prices->price['external_id']);
        $this->assertSame('1001', (string)$offer->prices->price['value']);
        $this->assertSame('UAH', (string)$offer->prices->price['currency']);
        $this->assertCount(0, $offer->description, 'empty fields are dropped');

        $this->assertSame('product-2', (string)$this->xml($this->call(['type' => 'get_catalog', 'mode' => 'query']))->offers->offer['external_id']);
        $this->assertSame(['success', 'Uploaded 2 of 2 products from the site'], $this->call(['type' => 'get_catalog', 'mode' => 'query']));
        $this->assertSame('progress', $this->call(['type' => 'get_catalog', 'mode' => 'query'])[0], 'the next query starts over from the classifier');
    }

    public function test_product_property_values_are_exported()
    {
        $this->login();
        $this->call(['type' => 'get_catalog', 'mode' => 'query']);

        $param = $this->xml($this->call(['type' => 'get_catalog', 'mode' => 'query']))->offers->offer->params->param;
        $this->assertSame('pa_color', (string)$param['external_id']);
        $this->assertSame('Red', (string)$param->values->value);
    }

    public function test_uploaded_offers_reach_the_rests_handler_and_the_file_is_deleted()
    {
        $this->login();

        $this->assertSame(['success', 'File uploaded!'], $this->upload('offers.xml', file_get_contents(dirname(__DIR__) . '/Fixtures/offers.xml')));
        $this->assertSame(['progress', 'Import and delete file offers.xml'], $this->call(['type' => 'catalog', 'mode' => 'import']));
        $this->assertSame(['success', 'All files was imported!'], $this->call(['type' => 'catalog', 'mode' => 'import']));

        $this->assertSame(['handler' => 'rests', 'product_id' => 'product-1', 'data' => ['id' => 'main', 'quantity' => '7']], $this->calls()[0]);
    }

    public function test_an_uploaded_zip_is_extracted_on_the_first_import_request()
    {
        $zipPath = self::$workdir . '/offers.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile(dirname(__DIR__) . '/Fixtures/offers.xml', 'offers.xml');
        $zip->addFromString('readme.txt', 'not an exchange file');
        $zip->close();

        $this->login();
        $this->upload('offers.zip', file_get_contents($zipPath));

        $this->assertSame(['progress', 'Extracting zip file offers.zip'], $this->call(['type' => 'catalog', 'mode' => 'import']));
        $this->assertSame(['offers.xml'], array_values(array_diff(scandir(self::$workdir . '/flamixml/import'), ['.', '..'])));
        $this->assertSame(['progress', 'Import and delete file offers.xml'], $this->call(['type' => 'catalog', 'mode' => 'import']));
        $this->assertSame('product-1', $this->calls()[0]['product_id']);
    }

    public function test_every_warehouse_of_an_offer_reaches_the_rests_handler()
    {
        $this->importOffers();

        $this->assertSame([
            ['id' => 'main', 'quantity' => '3'],
            ['id' => 'reserve', 'quantity' => '4'],
        ], $this->handlerData('rests', 'product-2'));
    }

    public function test_offer_prices_reach_the_prices_handler()
    {
        $this->importOffers();

        $this->assertSame(['type' => 'BASE', 'currency' => 'UAH', 'value' => '1002'], $this->handlerData('prices', 'product-2'));
        $this->assertSame([
            ['type' => 'BASE', 'currency' => 'UAH', 'value' => '30.5'],
            ['type' => 'SALE', 'currency' => 'UAH', 'value' => '25'],
        ], $this->handlerData('prices', 'product-3'));
    }

    public function test_offer_fields_reach_the_products_handler()
    {
        $this->importOffers();

        $this->assertSame(['id' => 'product-3', 'name' => 'Product 3', 'sku' => 'SKU-3'], $this->handlerData('products', 'product-3'));
        $this->assertCount(1, array_keys(array_column($this->calls(), 'handler'), 'products'), 'an offer with only stock or prices does not call it');
    }

    public function test_a_commerceml_file_is_rejected_and_stays_until_init()
    {
        $this->login();
        $this->upload('offers.xml', '<?xml version="1.0" encoding="UTF-8"?><КоммерческаяИнформация><ПакетПредложений/></КоммерческаяИнформация>');

        $this->assertSame(['failure', 'Error: Empty offers in file: offers.xml'], $this->call(['type' => 'catalog', 'mode' => 'import']));
        $this->assertSame([], $this->calls());
    }

    public function test_the_etalons_read_and_write_back_unchanged()
    {
        $dir = dirname(__DIR__, 2) . '/etalons/';
        $classifier = file_get_contents($dir . 'expected/flamix-0.xml');
        $offers = file_get_contents($dir . 'expected/flamix-1.xml');

        $this->assertSame($classifier, FlamixML::classifier(FlamixML::readCategories($classifier), FlamixML::readProperties($classifier), FlamixML::readWarehouses($classifier)));
        $this->assertSame($offers, FlamixML::offers(FlamixML::readProducts($offers)));
    }

    public function test_the_export_examples_are_readable()
    {
        $dir = dirname(__DIR__, 2) . '/etalons/';

        $this->assertCount(2, FlamixML::readCategories(file_get_contents($dir . 'import_0.xml')));
        $this->assertCount(3, FlamixML::readProperties(file_get_contents($dir . 'import_0.xml')));
        $this->assertCount(4, FlamixML::readProducts(file_get_contents($dir . 'import_1.xml')));
        $this->assertNotEmpty(FlamixML::readProducts(file_get_contents($dir . 'import_2.xml')));
    }

    public function test_upload_accepts_only_a_bare_xml_or_zip_name()
    {
        $this->login();

        $this->assertSame(['failure', 'Only .xml and .zip files are accepted!'], $this->upload('offers.txt', 'x'));
        $this->assertSame(['success', 'File uploaded!'], $this->upload('nested/offers.xml', '<xml/>'));
        $this->assertSame(['offers.xml'], array_values(array_diff(scandir(self::$workdir . '/flamixml/import'), ['.', '..'])));
    }

    public function test_init_clears_the_import_directory()
    {
        $this->login();
        $this->upload('offers.xml', '<xml/>');

        $this->assertSame(['success', 'Removed directory: import'], $this->call(['type' => 'catalog', 'mode' => 'init']));
        $this->assertSame(['success', 'All files was imported!'], $this->call(['type' => 'catalog', 'mode' => 'import']));
    }

    private function importOffers(): void
    {
        $this->login();
        $this->upload('offers.xml', file_get_contents(dirname(__DIR__) . '/Fixtures/offers.xml'));
        $this->call(['type' => 'catalog', 'mode' => 'import']);
    }

    private function handlerData(string $handler, string $product_id): array
    {
        foreach ($this->calls() as $call) {
            if ($call['handler'] === $handler && $call['product_id'] === $product_id)
                return $call['data'];
        }

        $this->fail("{$handler} was not called for {$product_id}");
    }

    private function login(): array
    {
        $lines = $this->call(['type' => 'catalog', 'mode' => 'checkauth'], null, 'login:secret');
        $this->cookie = $lines[2] ?? null;
        return $lines;
    }

    private function upload(string $filename, string $content): array
    {
        return $this->call(['type' => 'catalog', 'mode' => 'file', 'filename' => $filename], $content);
    }

    private function call(array $query, ?string $body = null, ?string $basicAuth = null): array
    {
        $headers = [];
        if ($this->cookie)
            $headers[] = 'Cookie: PHPSESSID=' . $this->cookie;
        if ($basicAuth)
            $headers[] = 'Authorization: Basic ' . base64_encode($basicAuth);

        $response = file_get_contents(self::$url . '?' . http_build_query($query), false, stream_context_create(['http' => [
            'method' => $body === null ? 'GET' : 'POST',
            'header' => array_merge($headers, ['Content-Type: application/octet-stream']),
            'content' => (string)$body,
            'ignore_errors' => true,
        ]]));

        return explode(PHP_EOL, $response);
    }

    private function xml(array $lines): \SimpleXMLElement
    {
        return new \SimpleXMLElement(implode(PHP_EOL, array_slice($lines, 2)));
    }

    private function calls(): array
    {
        return json_decode(@file_get_contents(self::$workdir . '/calls.json') ?: '[]', true);
    }
}
