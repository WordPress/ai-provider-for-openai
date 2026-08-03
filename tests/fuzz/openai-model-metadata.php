<?php

declare(strict_types=1);

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\DTO\RequiredOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\OpenAiAiProvider\Metadata\OpenAiModelMetadataDirectory;

return static function (): array {
    $providerRoot = '/wordpress/wp-content/plugins/ai-provider-for-openai';
    $clientRoot = '/wordpress/wp-content/plugins/php-ai-client-pr';

    require $clientRoot . '/vendor/autoload.php';
    require $providerRoot . '/src/Metadata/OpenAiModelMetadataDirectory.php';

    $directory = new class () extends OpenAiModelMetadataDirectory {
        /** @param list<string> $modelIds */
        public function explicit(array $modelIds): array
        {
            return $this->createModelMetadataForExplicitModelIds($modelIds);
        }

        /** @param list<string> $modelIds */
        public function listed(array $modelIds): array
        {
            $response = new Response(
                200,
                ['content-type' => 'application/json'],
                json_encode(
                    ['data' => array_map(
                        static fn(string $modelId): array => ['id' => $modelId],
                        $modelIds
                    )],
                    JSON_THROW_ON_ERROR
                )
            );
            $map = [];
            foreach ($this->parseResponseToModelMetadataList($response) as $model) {
                $map[$model->getId()] = $model;
            }
            return $map;
        }
    };

    $failures = [];
    $checks = 0;
    $assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
        ++$checks;
        if (!$condition) {
            $failures[] = $message;
        }
    };

    $accepted = [
        'gpt-3.5-turbo',
        'gpt-4',
        'gpt-4o',
        'gpt-4.1',
        'gpt-5',
        'gpt-5.4',
        'gpt-5.4-mini',
        'chatgpt-4o-latest',
        'o1',
        'o3-mini',
        'o4',
        'o5-pro',
        'codex-mini-latest',
    ];
    $suffixes = ['mini', 'nano', 'preview', '2026-08-03'];
    mt_srand(23123);
    for ($i = 0; $i < 240; ++$i) {
        if ($i % 3 === 0) {
            $accepted[] = sprintf('gpt-5.%d-%s-%04d', mt_rand(0, 9), $suffixes[$i % 4], $i);
        } elseif ($i % 3 === 1) {
            $accepted[] = sprintf('chatgpt-%d.%d-%s-%04d', mt_rand(4, 9), mt_rand(0, 9), $suffixes[$i % 4], $i);
        } else {
            $accepted[] = sprintf('o%d-%s-%04d', mt_rand(0, 9), $suffixes[$i % 4], $i);
        }
    }
    $accepted = array_values(array_unique($accepted));

    $excluded = [
        'gpt-image-1',
        'dall-e-3',
        'tts-1',
        'gpt-4o-mini-tts',
        'gpt-4o-realtime-preview',
        'gpt-4o-transcribe',
        'gpt-3.5-turbo-instruct',
        'whisper-1',
        'text-embedding-3-large',
        'omni-moderation-latest',
        'o',
        'o-3',
        'vendor/model',
        '',
    ];
    for ($i = 0; $i < 240; ++$i) {
        if ($i % 6 === 0) {
            $excluded[] = sprintf('gpt-image-%d-%04d', mt_rand(1, 9), $i);
        } elseif ($i % 6 === 1) {
            $excluded[] = sprintf('gpt-5.%d-realtime-%04d', mt_rand(0, 9), $i);
        } elseif ($i % 6 === 2) {
            $excluded[] = sprintf('gpt-5.%d-transcribe-%04d', mt_rand(0, 9), $i);
        } elseif ($i % 6 === 3) {
            $excluded[] = sprintf('gpt-5.%d-instruct-%04d', mt_rand(0, 9), $i);
        } elseif ($i % 6 === 4) {
            $excluded[] = sprintf('tts-%d-%04d', mt_rand(1, 9), $i);
        } else {
            $excluded[] = sprintf('unrelated-%08x', mt_rand());
        }
    }
    $excluded = array_values(array_unique($excluded));

    $explicit = $directory->explicit(array_merge($accepted, $excluded));
    $listed = $directory->listed(array_merge($accepted, $excluded));

    $message = [new UserMessage([new MessagePart('hello')])];
    $basicRequirements = ModelRequirements::fromPromptData(
        CapabilityEnum::textGeneration(),
        $message,
        new ModelConfig()
    );
    $configuredModel = new ModelConfig();
    $configuredModel->setSystemInstruction('Be concise.');
    $configuredModel->setMaxTokens(200);
    $configuredModel->setTemperature(0.5);
    $configuredModel->setTopP(0.9);
    $configuredModel->setStopSequences(['STOP']);
    $configuredModel->setOutputMimeType('application/json');
    $configuredModel->setOutputModalities([ModalityEnum::text()]);
    $configuredRequirements = ModelRequirements::fromPromptData(
        CapabilityEnum::textGeneration(),
        $message,
        $configuredModel
    );
    $chatRequirements = ModelRequirements::fromPromptData(
        CapabilityEnum::textGeneration(),
        [
            new UserMessage([new MessagePart('first')]),
            new UserMessage([new MessagePart('second')]),
        ],
        new ModelConfig()
    );

    foreach ($accepted as $modelId) {
        $assert(isset($explicit[$modelId]), 'accepted ID omitted: ' . $modelId);
        if (!isset($explicit[$modelId], $listed[$modelId])) {
            continue;
        }
        $assert($explicit[$modelId]->toArray() === $listed[$modelId]->toArray(), 'listed/direct mismatch: ' . $modelId);
        $assert($basicRequirements->areMetBy($explicit[$modelId]), 'basic text unsupported: ' . $modelId);
        $assert($configuredRequirements->areMetBy($explicit[$modelId]), 'configured text unsupported: ' . $modelId);
        $assert($chatRequirements->areMetBy($explicit[$modelId]), 'chat history unsupported: ' . $modelId);
    }
    foreach ($excluded as $modelId) {
        $assert(!isset($explicit[$modelId]), 'non-text ID bypassed models listing: ' . $modelId);
    }

    $multimodalRequirements = new ModelRequirements(
        [CapabilityEnum::textGeneration()],
        [new RequiredOption(
            OptionEnum::inputModalities(),
            [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::document()]
        )]
    );
    $assert($multimodalRequirements->areMetBy($explicit['gpt-5.4']), 'gpt-5.4 multimodal input unsupported');
    $assert(!$multimodalRequirements->areMetBy($explicit['gpt-3.5-turbo']), 'legacy model accepted multimodal input');

    $direct = $directory->getModelMetadata('gpt-5.4');
    $assert($direct->toArray() === $explicit['gpt-5.4']->toArray(), 'public direct lookup metadata mismatch');
    $assert($basicRequirements->areMetBy($direct), 'public direct lookup fails basic requirements');
    $assert($directory->explicit([]) === [], 'empty batch returned metadata');
    $assert(count($directory->explicit(['gpt-5.4', 'gpt-5.4'])) === 1, 'duplicate IDs were not deduplicated');

    if ($failures) {
        throw new RuntimeException(json_encode(['checks' => $checks, 'failures' => $failures], JSON_THROW_ON_ERROR));
    }

    return [
        'schema' => 'php-ai-client/openai-metadata-fuzz-result/v1',
        'status' => 'passed',
        'checks' => $checks,
        'acceptedModelIds' => count($accepted),
        'excludedModelIds' => count($excluded),
        'dimensions' => [
            'basic-text-support',
            'configured-text-support',
            'chat-history-support',
            'multimodal-support-boundary',
            'listed-direct-consistency',
            'non-text-fallback',
            'public-direct-no-list',
            'empty-and-duplicate-batches',
        ],
    ];
};
