<?php

namespace Mnabialek\LaravelSqlLogger;

use Mnabialek\LaravelSqlLogger\Objects\SqlQuery;
use Mnabialek\LaravelVersion\Version;

class Query
{
    /**
     * @var Version
     */
    private $version;

    /**
     * Query constructor.
     *
     * @param Version $version
     */
    public function __construct(Version $version)
    {
        $this->version = $version;
    }

    /**
     * @param int $number
     * @param string|\Illuminate\Database\Events\QueryExecuted $query
     * @param array|null $bindings
     * @param float|null $time
     * @param string|null $caller
     *
     * @return SqlQuery
     */
    public function get($number, $query, $bindings = null, $time = null, $caller = null)
    {
        // for Laravel/Lumen 5.2+ $query is object and it holds all the data
        if ($this->version->min('5.2.0')) {
            $bindings = $query->bindings;
            $time = $query->time;
            $query = $query->sql;

            if ($query instanceof \Illuminate\Database\Query\Expression) {
                try {
                    $conn = app('db')->connection();
                    $query = (string) $query->getValue($conn->getQueryGrammar());
                } catch (\Throwable $e) {
                    $query = '[Expression]';
                }
            }
        }

        return new SqlQuery($number, $query, $time, $bindings, $caller);
    }
}
