<?php

/**
 * PHPUnit bootstrap file for the AI Provider for OpenAI package.
 *
 * @since 1.1.0
 *
 * @package WordPress\OpenAiAiProvider
 */

declare(strict_types=1);

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
