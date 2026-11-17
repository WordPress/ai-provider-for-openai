<?php

/**
 * PHPUnit bootstrap file for the AI Provider for OpenAI package.
 *
 * @since 1.1.0
 *
 * @package WordPress\OpenAiAiProvider
 */

declare(strict_types=1);

// This test bootstrap loads autoloaders and supplies an optional WordPress filter stub.
// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

/*
 * Load the PHP AI Client SDK. Prefer the Composer autoloader; alternatively, a standalone
 * autoloader for the SDK may be provided via the PHP_AI_CLIENT_AUTOLOAD environment variable
 * (useful in environments where Composer dependencies cannot be installed).
 */
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
$sdkAutoload = getenv('PHP_AI_CLIENT_AUTOLOAD');
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} elseif (is_string($sdkAutoload) && $sdkAutoload !== '' && file_exists($sdkAutoload)) {
    require_once $sdkAutoload;
}

// Load this package's classes.
require_once dirname(__DIR__) . '/src/autoload.php';

if (!function_exists('apply_filters')) {
    /**
     * Minimal filter dispatcher for the profile resolver's WordPress integration.
     *
     * @param string $hookName The filter name.
     * @param mixed $value The filtered value.
     * @param mixed ...$args Additional filter arguments.
     * @return mixed The filtered value.
     */
    function apply_filters(string $hookName, $value, ...$args)
    {
        foreach ($GLOBALS['openai_profile_test_filters'][$hookName] ?? [] as $callback) {
            $value = $callback($value, ...$args);
        }

        return $value;
    }
}
