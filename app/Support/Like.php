<?php

namespace App\Support;

/**
 * Patterns for SQL LIKE built from what a user typed, so `%`, `_` and `\` in it match themselves.
 */
final class Like
{
    /** A pattern matching values that contain the text anywhere. */
    public static function contains(string $text): string
    {
        return '%'.addcslashes($text, '\\%_').'%';
    }
}
