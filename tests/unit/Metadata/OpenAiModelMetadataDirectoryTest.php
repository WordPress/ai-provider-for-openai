<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Tests\unit\Metadata;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\OpenAiAiProvider\Metadata\OpenAiModelMetadataDirectory;

/**
 * @covers \WordPress\OpenAiAiProvider\Metadata\OpenAiModelMetadataDirectory
 */
class OpenAiModelMetadataDirectoryTest extends TestCase
{
    /**
     * Tests explicit text model metadata satisfies basic text prompt requirements.
     */
    public function testExplicitTextModelMetadataSupportsBasicTextPrompt(): void
    {
        $directory = new class () extends OpenAiModelMetadataDirectory {
            public function getExplicitModelMetadata(string $modelId): ?ModelMetadata
            {
                return $this->createModelMetadataForExplicitModelIds([$modelId])[$modelId] ?? null;
            }
        };

        $metadata = $directory->getExplicitModelMetadata('gpt-5.6');
        $requirements = ModelRequirements::fromPromptData(
            CapabilityEnum::textGeneration(),
            [new UserMessage([new MessagePart('Hello')])],
            new ModelConfig()
        );

        $this->assertNotNull($metadata);
        $this->assertTrue($requirements->areMetBy($metadata));
    }
}
