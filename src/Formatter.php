<?php

namespace Mnabialek\LaravelSqlLogger;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Mnabialek\LaravelSqlLogger\Objects\Concerns\ReplacesBindings;
use Mnabialek\LaravelSqlLogger\Objects\SqlQuery;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Connection;
class Formatter
{
    use ReplacesBindings;

    /**
     * @var Container
     */
    private $app;

    /**
     * @var Config
     */
    private $config;

    /**
     * Formatter constructor.
     *
     * @param Container $app
     * @param Config $config
     */
    public function __construct(Container $app, Config $config)
    {
        $this->app = $app;
        $this->config = $config;
    }

    /**
     * Get formatted line.
     *
     * @param SqlQuery $query
     *
     * @return string
     */
    public function getLine(SqlQuery $query)
    {
        $replace = [
            '[origin]' => $this->originLine(),
            '[query_nr]' => $query->number(),
            '[datetime]' => Carbon::now()->toDateTimeString(),
            '[query_time]' => $this->time($query->time()),
            '[query]' => $this->queryLine($query),
            '[separator]' => $this->separatorLine(),
            '[caller]' => (string) ($query->caller() ?: ''),
            '\n' => PHP_EOL,
        ];

        return str_replace(array_keys($replace), array_values($replace), $this->config->entryFormat());
    }

    /**
     * Format time.
     *
     * @param float $time
     *
     * @return string
     */
    protected function time($time)
    {
        return $this->config->useSeconds() ? ($time / 1000.0) . 's' : $time . 'ms';
    }

    /**
     * Get origin line.
     *
     * @return string
     */
    protected function originLine()
    {
        return 'Origin ' . ($this->app->runningInConsole()
                ? '(console): ' . $this->getArtisanLine()
                : '(request): ' . $this->getRequestLine());
    }

    /**
     * Get query line.
     *
     * @param SqlQuery $query
     *
     * @return string
     */
    protected function queryLine(SqlQuery $query)
    {
        $sql = $query->get();

        // Normalize non-string SQL (e.g. DB::raw() / Expression)
        $sql = $this->normalizeSql($sql);

        return $this->format($sql) . ';';
    }

    /**
     * Normalize SQL to string (handles Expression/objects).
     *
     * @param mixed $sql
     * @return string
     */
    protected function normalizeSql($sql): string
    {
        if (is_string($sql)) {
            return $sql;
        }

        // Laravel Expression (e.g. DB::raw($sql))
        if ($sql instanceof \Illuminate\Database\Query\Expression) {
            // Try to get grammar from current default connection if possible
            try {
                /** @var \Illuminate\Database\Connection $conn */
                $conn = $this->app['db']->connection();
                return (string) $sql->getValue($conn->getQueryGrammar());
            } catch (\Throwable $e) {
                // Best-effort fallback
                return method_exists($sql, 'getValue') ? (string) $sql->getValue() : '[Expression]';
            }
        }

        // Stringable objects
        if (is_object($sql) && method_exists($sql, '__toString')) {
            return (string) $sql;
        }

        // Last resort: avoid fatal conversion
        return is_scalar($sql) ? (string) $sql : '[NonStringSql:' . gettype($sql) . ']';
    }

    /**
     * Get Artisan line.
     *
     * @return string
     */
    protected function getArtisanLine()
    {
        $command = $this->app['request']->server('argv', []);

        if (is_array($command)) {
            $command = implode(' ', $command);
        }

        return $command;
    }

    /**
     * Get request line.
     *
     * @return string
     */
    protected function getRequestLine()
    {
        return $this->app['request']->method() . ' ' . $this->app['request']->fullUrl();
    }

    /**
     * Get separator line.
     *
     * @return string
     */
    protected function separatorLine()
    {
        return '/*' . str_repeat('=', 50) . '*/';
    }

    /**
     * Format given query.
     *
     * @param string $query
     *
     * @return string
     */
    protected function format($query)
    {
        return $this->removeNewLines($query);
    }

    /**
     * Remove new lines from SQL to keep it in single line if possible.
     *
     * @param string $sql
     *
     * @return string
     */
    protected function removeNewLines($sql)
    {
        if (! $this->config->newLinesToSpaces()) {
            return $sql;
        }

        return preg_replace($this->wrapRegex($this->notInsideQuotes('\v', false)), ' ', $sql);
    }
}
