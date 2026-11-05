<?php

namespace Flamix\FlamixML\Operations;

use Flamix\FlamixML\OneC\FlamixML;

/** The catalog import: unzips uploads, reads each FlamixML file and calls the handlers per offer. */
class Import
{
    public function __construct()
    {
        flamixml_log('Import init with dir: ' . $this->dir());
    }

    public function dir(): string
    {
        return flamixml_config('dir_import', 'import');
    }

    /** @throws \Exception */
    public function unzipAndDelete(string $filename): bool
    {
        flamixml_log('Start extracting file ' . $filename . ' and delete it!');
        return Files::exchange($this->dir())->extract($filename)->deleteFile($filename)->exist($filename);
    }

    /**
     * Extracts the first .zip and ends the request with progress; returns only when there is none.
     *
     * @throws \Exception
     */
    public function unzipAndDeleteAllFilesInFolderBySteps(): void
    {
        $zip_files = Files::exchange($this->dir())->find('.zip');
        $is_unzipped = count($zip_files) === 0;

        if (!$is_unzipped) {
            foreach ($zip_files as $zip_file) {
                $this->unzipAndDelete($zip_file);
                flamixml_response_by_type('progress', 'Extracting zip file ' . $zip_file);
            }
        }
    }

    /**
     * The offers of a FlamixML file, rows of FlamixML::readProducts().
     *
     * @throws \Exception
     */
    public function parseEntities(string $filename): array
    {
        $products = FlamixML::readProducts(Files::exchange($this->dir())->content($filename));

        flamixml_log('Get products from file ' . $filename, $products);
        return $products;
    }

    /**
     * Calls each handler with the offer id and its part of the offer, when that part is not empty.
     *
     * @throws \Exception
     */
    public function import(string $filename, $rests_fn, $prices_fn, $products_fn): bool
    {
        $entities = $this->parseEntities($filename);
        if (empty($entities))
            throw new \Exception('Empty offers in file: ' . $filename);

        foreach ($entities as $entity) {
            $product_id = $entity['xml_id'];

            $rests = $this->oneOrList(array_map(fn(array $stock) => ['id' => $stock['warehouse'], 'quantity' => $stock['value']], $entity['stocks']));
            if ($product_id && !empty($rests))
                call_user_func($rests_fn, $product_id, $rests);

            $prices = $this->oneOrList($entity['prices']);
            if ($product_id && !empty($prices))
                call_user_func($prices_fn, $product_id, $prices);

            $products = array_filter(['name' => $entity['name'], 'sku' => $entity['sku'], 'description' => $entity['description']], 'strlen');
            if ($product_id && !empty($products))
                call_user_func($products_fn, $product_id, ['id' => $product_id] + $products);
        }

        return true;
    }

    // Plugin handlers check $rests['quantity'] first: one row must stay a row, not a list of one.
    private function oneOrList(array $rows): array
    {
        return count($rows) === 1 ? $rows[0] : $rows;
    }

    /**
     * Imports and deletes the first .xml and ends the request with progress, or with success when none is left.
     *
     * @throws \Exception
     */
    public function importAllFilesInFolderBySteps($rests_fn, $prices_fn, $products_fn): void
    {
        $folder = Files::exchange($this->dir());
        $xml_files = $folder->find('.xml');

        if (!count($xml_files))
            flamixml_response_by_type('success', 'All files was imported!');

        foreach ($xml_files as $xml_file) {
            $this->import($xml_file, $rests_fn, $prices_fn, $products_fn);
            $folder->deleteFile($xml_file);
            flamixml_response_by_type('progress', 'Import and delete file ' . $xml_file);
        }
    }
}