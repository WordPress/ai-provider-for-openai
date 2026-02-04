# OpenAI Provider

An OpenAI provider for the [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK. Works as both a Composer package and a WordPress plugin.

## Requirements

- PHP 7.4 or higher
- [wordpress/php-ai-client](https://github.com/WordPress/php-ai-client) must be installed

## Installation

### As a Composer Package

```bash
composer require wordpress/openai-ai-provider
```

### As a WordPress Plugin

1. Download the plugin files
2. Upload to `/wp-content/plugins/openai-ai-provider/`
3. Ensure the PHP AI Client plugin is installed and activated
4. Activate the plugin through the WordPress admin

## Usage

### With WordPress

The provider automatically registers itself with the PHP AI Client on the `init` hook. Simply ensure both plugins are active and configure your API key:

```php
// Set your OpenAI API key (or use the OPENAI_API_KEY environment variable)
putenv('OPENAI_API_KEY=your-api-key');

// Use the provider
$result = AiClient::prompt('Hello, world!')
    ->usingProvider('openai')
    ->generateTextResult();
```

### As a Standalone Package

```php
use WordPress\AiClient\AiClient;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

// Register the provider
$registry = AiClient::defaultRegistry();
$registry->registerProvider(OpenAiProvider::class);

// Set your API key
putenv('OPENAI_API_KEY=your-api-key');

// Generate text
$result = AiClient::prompt('Explain quantum computing')
    ->usingProvider('openai')
    ->generateTextResult();

echo $result->toText();
```

## Supported Models

This provider dynamically discovers available models from the OpenAI API. Current flagship models include:

**Text Generation (GPT-5 Series)**
- `gpt-5.2` - Latest flagship model
- `gpt-5-mini` - Fast and affordable alternative
- `gpt-5-nano` - Fastest, most affordable reasoning model

**Text Generation (GPT-4 Series)**
- `gpt-4.1` - Improved coding and instruction following
- `gpt-4.1-mini`, `gpt-4.1-nano` - Smaller variants
- `gpt-4o` - Versatile multimodal model

**Image Generation**
- `gpt-image-1.5` - Latest image generation model
- `dall-e-3` - High-quality image generation

## Configuration

The provider uses the `OPENAI_API_KEY` environment variable for authentication. You can set this in your environment or via PHP:

```php
putenv('OPENAI_API_KEY=your-api-key');
```

## License

GPL-2.0-or-later
