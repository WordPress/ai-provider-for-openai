<?php
/**
 * Pure-PHP smoke test for generic provider profile metadata.
 *
 * Run with: php tests/provider-profile-smoke.php
 *
 * @package WordPress\OpenAiAiProvider\Tests
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('add_action')) {
    function add_action(string $hook_name, callable $callback, int $priority = 10): void
    {
        unset($hook_name, $callback, $priority);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook_name, callable $callback, int $priority = 10): void
    {
        $GLOBALS['__openai_provider_profile_filters'][$hook_name][] = $callback;
        unset($priority);
    }
}

require_once __DIR__ . '/../plugin.php';

$failures = [];
$passes = 0;

$assert = static function ($expected, $actual, string $label) use (&$failures, &$passes): void {
    if ($expected === $actual) {
        ++$passes;
        echo "PASS {$label}\n";
        return;
    }

    $failures[] = sprintf('%s expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
    echo "FAIL {$label}\n";
};

$profile = WordPress\OpenAiAiProvider\provider_profile();
$assert('ai-provider/profile/v1', $profile['schema'] ?? '', 'profile schema');
$assert('openai', $profile['provider'] ?? '', 'provider id');
$assert('https://api.openai.com/v1', $profile['base_url'] ?? '', 'base URL');
$assert('OPENAI_API_KEY', $profile['authentication']['env'] ?? '', 'auth env');
$assert('ai-provider-for-openai', $profile['plugin_source']['slug'] ?? '', 'plugin source slug');
$assert('wordpress/ai-provider-for-openai', $profile['plugin_source']['composer_package'] ?? '', 'composer package');
$assert(true, is_dir($profile['plugin_source']['path'] ?? ''), 'plugin source path exists');

$profiles = [];
foreach ($GLOBALS['__openai_provider_profile_filters']['ai_provider_profiles'] ?? [] as $callback) {
    $profiles = $callback($profiles);
}
$assert('openai', $profiles['openai']['provider'] ?? '', 'profile filter registration');

if (!empty($failures)) {
    echo "\nprovider profile smoke failed:\n" . implode("\n", $failures) . "\n";
    exit(1);
}

echo "\nprovider profile smoke passed ({$passes} assertions)\n";
