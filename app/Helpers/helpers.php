<?php

if (! function_exists('module_path')) {
    function module_path(string $name, string $path = ''): string
    {
        $modulesDir = config('app-modules.modules_directory', 'modules');

        return base_path($modulesDir.'/'.strtolower($name).($path ? '/'.ltrim($path, '/') : ''));
    }
}

if (! function_exists('safe_http_url')) {
    /** Allow only absolute http(s) URLs for links and media stored from imports. */
    function safe_http_url(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
