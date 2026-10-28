<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Support;

/**
 * Turns query bindings into {name, value} pairs, redacting values of sensitive columns
 * (e.g. `pin = ?`) and anything that looks like a password hash.
 */
final class QueryParams
{
    /**
     * @param  array<int|string, mixed>  $bindings  Bindings already prepared by the connection.
     * @return list<array{name: ?string, value: mixed}>
     */
    public static function from(string $sql, array $bindings, Redactor $redactor): array
    {
        $bindings = array_values($bindings);
        $names = BindingNames::infer($sql, count($bindings));
        $params = [];

        foreach (array_slice($bindings, 0, 100) as $index => $value) {
            $name = $names[$index] ?? null;

            if (($name !== null && $redactor->isSensitive($name)) || self::looksSecret($value)) {
                $value = Redactor::FILTERED;
            } elseif (is_string($value)) {
                $value = mb_check_encoding($value, 'UTF-8') ? mb_substr($value, 0, 500) : '[binary]';
            } elseif (! is_scalar($value) && $value !== null) {
                $value = get_debug_type($value);
            }

            $params[] = ['name' => $name, 'value' => $value];
        }

        return $params;
    }

    /**
     * Password hashes are secrets even when the column name is unknown.
     */
    private static function looksSecret(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', $value) === 1;
    }
}
