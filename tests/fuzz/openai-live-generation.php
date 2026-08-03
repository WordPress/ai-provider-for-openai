<?php

declare(strict_types=1);

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\OpenAiAiProvider\Models\OpenAiTextGenerationModel;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

$providerRoot = dirname(__DIR__, 2);
$clientPath = getenv('PHP_AI_CLIENT_PATH');
$clientRoot = is_string($clientPath) ? realpath($clientPath) : false;
if ($clientRoot === false) {
    throw new RuntimeException('PHP_AI_CLIENT_PATH must point to the matching PHP AI Client checkout.');
}

require $clientRoot . '/vendor/autoload.php';
require $providerRoot . '/src/Metadata/OpenAiModelMetadataDirectory.php';

$apiKey = getenv('OPENAI_API_KEY');
if (!is_string($apiKey) || $apiKey === '') {
    throw new RuntimeException('OPENAI_API_KEY is required for the live generation probe.');
}

$registry = new ProviderRegistry();
$registry->registerProvider(OpenAiProvider::class);
$registry->setProviderRequestAuthentication('openai', new ApiKeyRequestAuthentication($apiKey));

$config = new ModelConfig();
$config->setMaxTokens(16);
$model = $registry->getProviderModel('openai', 'gpt-5.4', $config);
if (!$model instanceof OpenAiTextGenerationModel) {
    throw new RuntimeException('Explicit gpt-5.4 did not resolve to the OpenAI text generation model.');
}

$result = $model->generateTextResult([
    new UserMessage([new MessagePart('Reply with exactly LIVE_OK and nothing else.')]),
]);
$candidates = $result->getCandidates();
if (!$candidates) {
    throw new RuntimeException('The live generation returned no candidates.');
}

$parts = $candidates[0]->getMessage()->getParts();
$text = isset($parts[0]) ? trim((string) $parts[0]->getText()) : '';
if ($text === '') {
    throw new RuntimeException('The live generation returned no text.');
}

echo json_encode([
    'schema' => 'php-ai-client/openai-live-generation-result/v1',
    'status' => 'passed',
    'model' => $result->getModelMetadata()->getId(),
    'responseMatched' => $text === 'LIVE_OK',
    'responseLength' => strlen($text),
    'candidateCount' => $result->getCandidateCount(),
    'totalTokens' => $result->getTokenUsage()->getTotalTokens(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
