<?php

namespace App\Services\Brands;

/**
 * Simple {{var}} template renderer for brand-aware AI prompts and copy.
 */
class BrandPromptTemplate
{
    /**
     * @param  array<string, string|int|float|null>  $vars
     */
    public static function render(string $template, array $vars): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
            $key = $m[1];

            return array_key_exists($key, $vars) ? (string) $vars[$key] : $m[0];
        }, $template);
    }
}
