<?php

/**
 * Plugin Name: AI Provider for OpenAI
 * Plugin URI: https://github.com/WordPress/ai-provider-for-openai
 * Description: AI Provider for OpenAI for the WordPress AI Client.
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Version: 1.0.3
 * Author: WordPress AI Team
 * Author URI: https://make.wordpress.org/ai/
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: ai-provider-for-openai
 *
 * @package WordPress\OpenAiAiProvider
 */

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider;

use WordPress\AiClient\AiClient;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

if (!defined('ABSPATH')) {
    return;
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the AI Provider for OpenAI with the AI Client.
 *
 * @since 1.0.0
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(OpenAiProvider::class)) {
        return;
    }

    $registry->registerProvider(OpenAiProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);

/**
 * Returns generic provider profile metadata for runtime consumers.
 *
 * @since 1.0.4
 *
 * @return array<string, mixed> Provider profile metadata.
 */
function provider_profile(): array
{
    return [
        'schema' => 'ai-provider/profile/v1',
        'provider' => 'openai',
        'name' => 'OpenAI',
        'base_url' => 'https://api.openai.com/v1',
        'authentication' => [
            'type' => 'api_key',
            'env' => 'OPENAI_API_KEY',
        ],
        'plugin_source' => [
            'type' => 'wordpress-plugin',
            'slug' => 'ai-provider-for-openai',
            'path' => __DIR__,
            'repository' => 'https://github.com/WordPress/ai-provider-for-openai',
            'composer_package' => 'wordpress/ai-provider-for-openai',
        ],
    ];
}

/**
 * Registers OpenAI in a generic provider profile registry.
 *
 * @since 1.0.4
 *
 * @param array<string, mixed> $profiles Provider profiles keyed by provider ID.
 * @return array<string, mixed> Provider profiles.
 */
function register_provider_profile(array $profiles): array
{
    $profiles['openai'] = provider_profile();

    return $profiles;
}

add_filter('ai_provider_profiles', __NAMESPACE__ . '\\register_provider_profile');
