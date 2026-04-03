<?php

namespace Silviooosilva\CacheerPhp\CacheStore\Support;

/**
 * Provides the shared chunked-write loop for all batch writer implementations.
 */
abstract class AbstractBatchWriter
{
    /**
     * @var int
     */
    protected int $batchSize;

    /**
     * @param int $batchSize
     */
    public function __construct(int $batchSize = 100)
    {
        $this->batchSize = $batchSize;
    }

    /**
     * @param array $items
     * @param string $namespace
     * @param callable $putItem
     *
     * @return void
     */
    public function write(array $items, string $namespace, callable $putItem): void
    {
        $processedCount = 0;
        $itemCount = count($items);

        while ($processedCount < $itemCount) {
            $batchItems = array_slice($items, $processedCount, $this->batchSize);
            foreach ($batchItems as $item) {
                $this->processItem($item, $namespace, $putItem);
            }
            $processedCount += count($batchItems);
        }
    }

    /**
     * @param array $item
     * @param string $namespace
     * @param callable $putItem
     *
     * @return void
     */
    abstract protected function processItem(array $item, string $namespace, callable $putItem): void;
}
