<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Tests\unit\Provider;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\OpenAiAiProvider\Metadata\OpenAiModelMetadataDirectory;
use WordPress\OpenAiAiProvider\Models\OpenAiEmbeddingGenerationModel;
use WordPress\OpenAiAiProvider\Models\OpenAiImageGenerationModel;
use WordPress\OpenAiAiProvider\Models\OpenAiTextGenerationModel;
use WordPress\OpenAiAiProvider\Models\OpenAiTextToSpeechConversionModel;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

/**
 * @covers \WordPress\OpenAiAiProvider\Provider\OpenAiProvider
 */
class OpenAiProviderTest extends TestCase
{
    /**
     * Tests URL resolution and endpoint concatenation with base URL.
     *
     * @return void
     */
    public function testBaseUrlAndUrlConstruction(): void
    {
        $this->assertSame('https://api.openai.com/v1', OpenAiProvider::url());
        $this->assertSame('https://api.openai.com/v1/models', OpenAiProvider::url('models'));
        $this->assertSame('https://api.openai.com/v1/responses', OpenAiProvider::url('/responses'));
    }

    /**
     * Tests that provider metadata is constructed with correct properties.
     *
     * @return void
     */
    public function testProviderMetadata(): void
    {
        $metadata = OpenAiProvider::metadata();

        $this->assertInstanceOf(ProviderMetadata::class, $metadata);
        $this->assertSame('openai', $metadata->getId());
        $this->assertSame('OpenAI', $metadata->getName());
        $this->assertTrue($metadata->getType()->isCloud());
        $this->assertSame('https://platform.openai.com/api-keys', $metadata->getCredentialsUrl());
        $this->assertTrue($metadata->getAuthenticationMethod()->isApiKey());

        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            $this->assertStringContainsString('GPT and Dall-E', $metadata->getDescription());
        }

        if (version_compare(AiClient::VERSION, '1.3.0', '>=')) {
            $logoPath = $metadata->getLogoPath();
            $this->assertNotNull($logoPath);
            $this->assertStringEndsWith('assets/images/openai.svg', $logoPath);
            $this->assertFileExists($logoPath);
        }
    }

    /**
     * Tests that modelMetadataDirectory returns the directory instance and caches it.
     *
     * @return void
     */
    public function testModelMetadataDirectory(): void
    {
        $directory1 = OpenAiProvider::modelMetadataDirectory();
        $directory2 = OpenAiProvider::modelMetadataDirectory();

        $this->assertInstanceOf(OpenAiModelMetadataDirectory::class, $directory1);
        $this->assertSame($directory1, $directory2);
    }

    /**
     * Tests that availability returns the availability checker instance and caches it.
     *
     * @return void
     */
    public function testProviderAvailability(): void
    {
        $availability1 = OpenAiProvider::availability();
        $availability2 = OpenAiProvider::availability();

        $this->assertInstanceOf(ListModelsApiBasedProviderAvailability::class, $availability1);
        $this->assertSame($availability1, $availability2);
    }

    /**
     * Tests that createModel creates an OpenAiTextGenerationModel for text-generation capability.
     *
     * @return void
     */
    public function testCreateModelWithTextGenerationCapability(): void
    {
        $modelMetadata = new ModelMetadata(
            'gpt-4o',
            'gpt-4o',
            [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()],
            []
        );

        $model = $this->createModelViaProvider($modelMetadata);

        $this->assertInstanceOf(OpenAiTextGenerationModel::class, $model);
    }

    /**
     * Tests that createModel creates an OpenAiImageGenerationModel for image-generation capability.
     *
     * @return void
     */
    public function testCreateModelWithImageGenerationCapability(): void
    {
        $modelMetadata = new ModelMetadata(
            'dall-e-3',
            'dall-e-3',
            [CapabilityEnum::imageGeneration()],
            []
        );

        $model = $this->createModelViaProvider($modelMetadata);

        $this->assertInstanceOf(OpenAiImageGenerationModel::class, $model);
    }

    /**
     * Tests that createModel creates an OpenAiEmbeddingGenerationModel for embedding capability.
     *
     * @return void
     */
    public function testCreateModelWithEmbeddingGenerationCapability(): void
    {
        if (!interface_exists(EmbeddingGenerationModelInterface::class)) {
            $this->markTestSkipped('Embedding generation requires PHP AI Client 1.4.0 or later.');
        }

        $modelMetadata = new ModelMetadata(
            'text-embedding-3-small',
            'text-embedding-3-small',
            [CapabilityEnum::embeddingGeneration()],
            []
        );

        $model = $this->createModelViaProvider($modelMetadata);

        $this->assertInstanceOf(OpenAiEmbeddingGenerationModel::class, $model);
    }

    /**
     * Tests that createModel creates an OpenAiTextToSpeechConversionModel for text-to-speech capability.
     *
     * @return void
     */
    public function testCreateModelWithTextToSpeechConversionCapability(): void
    {
        $modelMetadata = new ModelMetadata(
            'tts-1',
            'tts-1',
            [CapabilityEnum::textToSpeechConversion()],
            []
        );

        $model = $this->createModelViaProvider($modelMetadata);

        $this->assertInstanceOf(OpenAiTextToSpeechConversionModel::class, $model);
    }

    /**
     * Tests that createModel throws RuntimeException when unsupported capabilities are provided.
     *
     * @return void
     */
    public function testCreateModelThrowsRuntimeExceptionForUnsupportedCapabilities(): void
    {
        $modelMetadata = new ModelMetadata(
            'unsupported-model',
            'unsupported-model',
            [CapabilityEnum::speechGeneration()],
            []
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported model capabilities: speech_generation');

        $this->createModelViaProvider($modelMetadata);
    }

    /**
     * Tests that createModel throws RuntimeException when capabilities list is empty.
     *
     * @return void
     */
    public function testCreateModelThrowsRuntimeExceptionForEmptyCapabilities(): void
    {
        $modelMetadata = new ModelMetadata(
            'empty-model',
            'empty-model',
            [],
            []
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported model capabilities: ');

        $this->createModelViaProvider($modelMetadata);
    }

    /**
     * Helper to invoke protected static createModel on OpenAiProvider.
     *
     * @param ModelMetadata $modelMetadata The model metadata.
     * @return ModelInterface The instantiated model.
     */
    private function createModelViaProvider(ModelMetadata $modelMetadata): ModelInterface
    {
        $provider = new class extends OpenAiProvider {
            public static function exposeCreateModel(
                ModelMetadata $modelMetadata,
                ProviderMetadata $providerMetadata
            ): ModelInterface {
                return static::createModel($modelMetadata, $providerMetadata);
            }
        };

        return $provider::exposeCreateModel($modelMetadata, OpenAiProvider::metadata());
    }
}
