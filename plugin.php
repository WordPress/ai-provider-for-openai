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
 * Supplies OpenAI defaults for Data Machine's image generation capability.
 *
 * @since n.e.x.t
 *
 * @param array<string, mixed> $defaults Existing capability defaults.
 * @param string              $capability Capability identifier.
 * @return array<string, mixed> Filtered capability defaults.
 */
function filter_datamachine_ai_capability_defaults(array $defaults, string $capability): array
{
    if ($capability !== 'image_generation') {
        return $defaults;
    }

    return array_merge(
        $defaults,
        [
            'provider' => 'openai',
            'model' => 'gpt-image-1',
        ]
    );
}

add_filter('datamachine_ai_capability_defaults', __NAMESPACE__ . '\\filter_datamachine_ai_capability_defaults', 10, 2);
