<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Support;

/**
 * Best-effort column names for the positional "?" bindings of a SQL statement, so that
 * `where pin = ?` or `insert into users (password, ...)` values can be redacted by name.
 * Unknown positions are null; the value is then shown as-is.
 */
final class BindingNames
{
    /**
     * @return list<?string>
     */
    public static function infer(string $sql, int $count): array
    {
        $names = array_fill(0, $count, null);

        if ($count === 0) {
            return $names;
        }

        // insert into t (a, b, c) values (?, ?, ?), (?, ?, ?)
        if (preg_match('/^\s*insert\s+(?:ignore\s+)?into\s+\S+\s*\(([^)]*)\)\s*values/i', $sql, $match)) {
            $columns = array_map(fn (string $column): string => self::clean($column), explode(',', $match[1]));

            for ($i = 0; $i < $count; $i++) {
                $names[$i] = $columns[$i % max(1, count($columns))] ?? null;
            }

            return $names;
        }

        $offset = 0;

        for ($i = 0; $i < $count; $i++) {
            $position = self::nextPlaceholder($sql, $offset);

            if ($position === null) {
                break;
            }

            $before = substr($sql, max(0, $position - 160), min(160, $position));
            $offset = $position + 1;

            if (preg_match('/([`"\[\]\w.]+)\s*(?:=|<>|!=|<=|>=|<|>|\blike\b|\bnot like\b|\bilike\b)\s*$/i', $before, $match)
                || preg_match('/([`"\[\]\w.]+)\s+(?:not\s+)?in\s*\((?:\s*\?\s*,)*\s*$/i', $before, $match)
                || preg_match('/([`"\[\]\w.]+)\s+(?:not\s+)?between\s+(?:\?\s+and\s+)?$/i', $before, $match)) {
                $names[$i] = self::clean($match[1]);
            }
        }

        return $names;
    }

    private static function nextPlaceholder(string $sql, int $offset): ?int
    {
        $length = strlen($sql);
        $quote = null;

        for ($i = $offset; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'") {
                $quote = $char;
            } elseif ($char === '?') {
                return $i;
            }
        }

        return null;
    }

    private static function clean(string $identifier): string
    {
        $identifier = trim($identifier, " \t\n`\"[]");
        $parts = explode('.', $identifier);

        return trim((string) end($parts), '`"[]');
    }
}
