<?php

declare(strict_types=1);

namespace johnfmorton\llmready\helpers;

/**
 * Iterates an element query in batches on both Craft 5 and Craft 6.
 *
 * Craft 5's element queries are Yii queries: `each($batchSize)` returns a
 * batch iterator. Craft 6's are Laravel-style: `each()` takes a callback
 * instead, and `lazy($chunkSize)` is the batched iterator.
 */
final class QueryIterator
{
    /**
     * @param object $query a Craft element query
     * @return iterable<mixed>
     */
    public static function each(object $query, int $batchSize = 100): iterable
    {
        // Craft 6 (Laravel's BuildsQueries)
        if (method_exists($query, 'lazy')) {
            return $query->lazy($batchSize);
        }

        // Craft 5 (yii\db\Query)
        return $query->each($batchSize);
    }
}
