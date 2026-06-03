<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Tests\unit\Metadata;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\OpenAiAiProvider\Metadata\OpenAiModelMetadataDirectory;

/**
 * @covers \WordPress\OpenAiAiProvider\Metadata\OpenAiModelMetadataDirectory
 */
class OpenAiModelMetadataDirectoryTest extends TestCase
{
    public function testEmbeddingModelsAdvertiseEmbeddingCapability(): void
    {
        $directory = new class extends OpenAiModelMetadataDirectory {
            public function exposeParseResponseToModelMetadataList(Response $response): array
            {
                return $this->parseResponseToModelMetadataList($response);
            }
        };

        $models = $directory->exposeParseResponseToModelMetadataList(new Response(
            200,
            [],
            json_encode([
                'data' => [
                    ['id' => 'text-embedding-3-small'],
                    ['id' => 'gpt-4.1'],
                ],
            ])
        ));

        $embeddingModel = current(array_filter(
            $models,
            static function (ModelMetadata $modelMetadata): bool {
                return $modelMetadata->getId() === 'text-embedding-3-small';
            }
        ));

        $this->assertInstanceOf(ModelMetadata::class, $embeddingModel);
        $capabilities = $embeddingModel->getSupportedCapabilities();
        $options = $embeddingModel->getSupportedOptions();

        $this->assertTrue($capabilities[0]->isEmbeddingGeneration());
        $this->assertTrue($options[0]->getName()->isInputModalities());
        $this->assertTrue($options[1]->getName()->isEmbeddingDimensions());
        $this->assertTrue($options[2]->getName()->isCustomOptions());
    }
}
