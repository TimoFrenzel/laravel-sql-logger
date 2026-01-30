<?php

namespace Mnabialek\LaravelSqlLogger\Objects\Concerns;

use DateTimeInterface;

trait ReplacesBindings
{
    /**
     * Replace bindings.
     *
     * @param string $sql
     * @param array $bindings
     *
     * @return string
     */
    protected function replaceBindings($sql, array $bindings)
    {
        $generalRegex = $this->getRegex();

        foreach ($this->formatBindings($bindings) as $key => $binding) {
            $regex = is_numeric($key) ? $generalRegex : $this->getNamedParameterRegex($key);
            $sql = preg_replace($regex, $this->value($binding), $sql, 1);
        }

        return $sql;
    }

    /**
     * Get final value that will be displayed in query.
     *
     * @param mixed $value
     *
     * @return int|string
     */
    protected function value($value)
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return (int) $value;
        }

        if ($value instanceof \Illuminate\Database\Query\Expression) {
            try {
                $conn = app('db')->connection();
                return (string) $value->getValue($conn->getQueryGrammar());
            } catch (\Throwable $e) {
                return "'[Expression]'";
            }
        }

        if (is_object($value) && ! method_exists($value, '__toString')) {
            return "'[Object]'";
        }

        if (is_object($value)) {
            $value = (string) $value;
        }

        // ints/floats remain unquoted; numeric strings stay quoted (safer in logs)
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // escape single quotes for log output
        $value = str_replace("'", "\\'", (string) $value);

        return "'" . $value . "'";
    }

    /**
     * Get regex to be used for named parameter with given name.
     *
     * @param string $name
     *
     * @return string
     */
    protected function getNamedParameterRegex($name)
    {
        if (mb_substr($name, 0, 1) == ':') {
            $name = mb_substr($name, 1);
        }

        return $this->wrapRegex($this->notInsideQuotes('\:' . preg_quote($name), false));
    }

    /**
     * Format bindings values.
     *
     * @param array $bindings
     *
     * @return array
     */
    protected function formatBindings($bindings)
    {
        $conn = null;
        $grammar = null;

        foreach ($bindings as $key => $binding) {

            if ($binding instanceof \Illuminate\Database\Query\Expression) {
                try {
                    if ($conn === null) {
                        $conn = app('db')->connection();
                        $grammar = $conn->getQueryGrammar();
                    }
                    $bindings[$key] = (string) $binding->getValue($grammar);
                } catch (\Throwable $e) {
                    $bindings[$key] = '[Expression]';
                }
                continue;
            }

            if ($binding instanceof \DateTimeInterface) {
                $bindings[$key] = $binding->format('Y-m-d H:i:s');
                continue;
            }

            if ($binding instanceof \BackedEnum) {
                $bindings[$key] = $binding->value;
                continue;
            }

            if (is_array($binding)) {
                $bindings[$key] = json_encode($binding, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                continue;
            }

            if (is_object($binding) && method_exists($binding, '__toString')) {
                $bindings[$key] = (string) $binding;
                continue;
            }

            if (is_string($binding)) {
                // minimal escaping for logger output
                $bindings[$key] = str_replace("'", "\\'", $binding);
                continue;
            }
        }

        return $bindings;
    }


    /**
     * Get regex to be used to replace bindings.
     *
     * @return string
     */
    protected function getRegex()
    {
        return $this->wrapRegex(
            $this->notInsideQuotes('?')
            . '|' .
            $this->notInsideQuotes('\:\w+', false)
        );
    }

    /**
     * Wrap regex.
     *
     * @param string $regex
     *
     * @return string
     */
    protected function wrapRegex($regex)
    {
        return '#' . $regex . '#ms';
    }

    /**
     * Create partial regex to find given text not inside quotes.
     *
     * @param string $string
     * @param bool $quote
     *
     * @return string
     */
    protected function notInsideQuotes($string, $quote = true)
    {
        if ($quote) {
            $string = preg_quote($string);
        }

        return
            // double quotes - ignore "" and everything inside quotes for example " abc \"err "
            '(?:""|"(?:[^"]|\\")*?[^\\\]")(*SKIP)(*F)|' . $string .
            '|' .
            // single quotes - ignore '' and everything inside quotes for example ' abc \'err '
            '(?:\\\'\\\'|\'(?:[^\']|\\\')*?[^\\\]\')(*SKIP)(*F)|' . $string;
    }
}
