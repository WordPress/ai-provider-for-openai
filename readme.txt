=== AI Provider for OpenAI ===
Contributors: wordpressdotorg
Tags: ai, openai, gpt, chatgpt, artificial-intelligence
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI Provider for OpenAI for the PHP AI Client SDK.

== Description ==

This plugin provides OpenAI integration for the PHP AI Client SDK. It enables WordPress sites to use OpenAI's GPT models for text generation, DALL-E for image generation, and other AI capabilities.

**Features:**

* Text generation with GPT models
* Image generation with DALL-E models
* Function calling support
* Web search support
* Automatic provider registration

Available models are dynamically discovered from the OpenAI API, including GPT models for text generation, DALL-E and GPT Image models for image generation, and TTS models for text-to-speech.

**Requirements:**

* PHP 7.4 or higher
* WordPress 7.0 or higher
    * If using an older WordPress release, the [wordpress/php-ai-client](https://github.com/WordPress/php-ai-client) package must be installed
* OpenAI API key

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ai-provider-for-openai/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure your OpenAI API key via the `OPENAI_API_KEY` environment variable or constant

== Frequently Asked Questions ==

= How do I get an OpenAI API key? =

Visit the [OpenAI Platform](https://platform.openai.com/) to create an account and generate an API key.

= Does this plugin work without the PHP AI Client? =

No, this plugin requires the PHP AI Client plugin to be installed and activated. It provides the OpenAI-specific implementation that the PHP AI Client uses.

== Changelog ==

= 1.0.0 =
* Initial release
* Support for GPT text generation models
* Support for DALL-E image generation models
* Function calling support
* Web search support

== Upgrade Notice ==

= 1.0.0 =
Initial release.
