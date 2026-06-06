<?php
/**
 * GLOBAL-namespace fallback shims for GmailDwdMailTest, used ONLY when the test
 * runs outside a full Laravel boot (so config()/now() aren't already defined).
 *
 * This file deliberately has NO namespace so the functions land in the global
 * namespace, which is where App\Misc\Mail's unqualified config()/now() calls
 * fall back to. (Defining them inside the test class's Tests\Unit namespace
 * would create Tests\Unit\config, which PHP would NOT use as the global
 * fallback.) Under the project's normal PHPUnit run, Laravel has already
 * defined the real config()/now() and these guarded definitions are skipped.
 */

if (!isset($GLOBALS['__gmaildwd_config'])) {
    $GLOBALS['__gmaildwd_config'] = [];
}

if (!function_exists('config')) {
    function config($key, $default = null)
    {
        return $GLOBALS['__gmaildwd_config'][$key] ?? $default;
    }
}

if (!function_exists('now')) {
    function now()
    {
        return new class {
            public function toDateTimeString()
            {
                return '2026-01-01 00:00:00';
            }
        };
    }
}
