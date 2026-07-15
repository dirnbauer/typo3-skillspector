<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Support;

/**
 * Safe scalar extraction from mixed values (database rows, extension
 * configuration, decoded JSON, request bodies).
 */
final class Typed
{
    private function __construct()
    {
    }

    public static function string(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value instanceof \Stringable) {
            return (string)$value;
        }
        return '';
    }

    public static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    public static function stringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $item) {
            $result[(string)$key] = $item;
        }
        return $result;
    }
}


