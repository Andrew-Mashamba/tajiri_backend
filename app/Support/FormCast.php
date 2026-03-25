<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class FormCast
{
    /**
     * Validation rule for allow_comments (and similar) from multipart form data.
     * Accepts string "1", "0", "true", "false" and actual booleans/ints.
     */
    public static function allowCommentsRule(): array
    {
        return ['nullable', Rule::in([0, 1, '0', '1', true, false, 'true', 'false'])];
    }

    /**
     * Accepts "1", "0", "true", "false" (and actual booleans/ints) from multipart form data
     * and returns a boolean. If $value is null or empty and $default is set, returns $default.
     */
    public static function toBoolean(mixed $value, ?bool $default = null): ?bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
