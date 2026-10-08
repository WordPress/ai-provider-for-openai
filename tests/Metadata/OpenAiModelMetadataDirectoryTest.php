<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\OpenAiAiProvider\Metadata\OpenAiModelMetadataDirectory;

/**
 * Tests for the OpenAI model metadata directory.
 *
 * @since 1.1.0
 */
class OpenAiModelMetadataDirectoryTest extends TestCase
{
    /**
     * Tests that sampling option support is advertised for exactly the right models.
     *
     * The sampling options (`temperature`, `top_p`, `logprobs`, and `top_logprobs`) are supported
     * by the currently documented standard GPT models and by reasoning models whose default
     * reasoning effort is `none`. Other reasoning models must not advertise these options, since a
     * configured option becomes a required option during requirement-based model resolution.
     *
     * @dataProvider samplingOptionSupportProvider
     *
     * @param string $modelId The model ID returned by the OpenAI models endpoint.
     * @param bool $expectedSupport Whether the sampling options should be advertised as supported.
     */
    public function testSamplingOptionSupport(string $modelId, bool $expectedSupport): void
    {
        $modelMetadata = $this->parseSingleModelMetadata($modelId);
        $optionNames = $this->getSupportedOptionNames($modelMetadata);

        // Sanity check: the model must be classified as a text generation model.
        $this->assertContains(
            OptionEnum::maxTokens()->value,
            $optionNames,
            sprintf('Expected model "%s" to be classified as a text generation model.', $modelId)
        );

        $samplingOptions = [
            OptionEnum::temperature(),
            OptionEnum::topP(),
            OptionEnum::logprobs(),
            OptionEnum::topLogprobs(),
        ];
        foreach ($samplingOptions as $option) {
            if ($expectedSupport) {
                $this->assertContains(
                    $option->value,
                    $optionNames,
                    sprintf('Expected model "%s" to support the "%s" option.', $modelId, $option->value)
                );
            } else {
                $this->assertNotContains(
                    $option->value,
                    $optionNames,
                    sprintf('Expected model "%s" to not support the "%s" option.', $modelId, $option->value)
                );
            }
        }
    }

    /**
     * Data provider for currently documented models' sampling option support.
     *
     * @return array<string, array{string, bool}> Test cases with model ID and expected support.
     */
    public static function samplingOptionSupportProvider(): array
    {
        return [
            'gpt-5 (reasoning always enabled)' => ['gpt-5', false],
            'gpt-5-mini (reasoning always enabled)' => ['gpt-5-mini', false],
            'gpt-5-pro (reasoning always enabled)' => ['gpt-5-pro', false],
            'gpt-5.1 (default reasoning effort none)' => ['gpt-5.1', true],
            'gpt-5.1 dated snapshot' => ['gpt-5.1-2025-11-13', true],
            'gpt-5.2 (default reasoning effort none)' => ['gpt-5.2', true],
            'gpt-5.2 dated snapshot' => ['gpt-5.2-2025-12-11', true],
            'gpt-5.4 (default reasoning effort none)' => ['gpt-5.4', true],
            'gpt-5.4 dated snapshot' => ['gpt-5.4-2026-03-05', true],
            'gpt-5.4-mini (default reasoning effort none)' => ['gpt-5.4-mini', true],
            'gpt-5.4-mini dated snapshot' => ['gpt-5.4-mini-2026-03-17', true],
            'gpt-5.4-nano (default reasoning effort none)' => ['gpt-5.4-nano', true],
            'gpt-5.4-nano dated snapshot' => ['gpt-5.4-nano-2026-03-17', true],
            'gpt-5.2-pro (cannot disable reasoning)' => ['gpt-5.2-pro', false],
            'gpt-5.2-codex (cannot disable reasoning)' => ['gpt-5.2-codex', false],
            'gpt-5-chat-latest (non-reasoning chat alias)' => ['gpt-5-chat-latest', true],
            'gpt-5.1-chat-latest (live API rejected temperature)' => ['gpt-5.1-chat-latest', false],
            'gpt-5.2-chat-latest (live API rejected temperature)' => ['gpt-5.2-chat-latest', false],
            'fine-tuned gpt-5.2' => ['ft:gpt-5.2:example-org:example-model', true],
            'fine-tuned gpt-5.2-codex' => ['ft:gpt-5.2-codex:example-org:example-model', false],
            'codex-mini-latest (reasoning always enabled)' => ['codex-mini-latest', false],
            'o3 (reasoning always enabled)' => ['o3', false],
            'o4-mini (reasoning always enabled)' => ['o4-mini', false],
            'gpt-4o (standard GPT model)' => ['gpt-4o', true],
            'gpt-4.1 (standard GPT model)' => ['gpt-4.1', true],
        ];
    }

    /**
     * Tests that non-sampling base options remain supported for reasoning models.
     */
    public function testReasoningModelKeepsBaseOptions(): void
    {
        $modelMetadata = $this->parseSingleModelMetadata('gpt-5');
        $optionNames = $this->getSupportedOptionNames($modelMetadata);

        $expectedOptions = [
            OptionEnum::systemInstruction(),
            OptionEnum::maxTokens(),
            OptionEnum::functionDeclarations(),
            OptionEnum::customOptions(),
            OptionEnum::inputModalities(),
            OptionEnum::outputModalities(),
        ];
        foreach ($expectedOptions as $option) {
            $this->assertContains(
                $option->value,
                $optionNames,
                sprintf('Expected model "gpt-5" to support the "%s" option.', $option->value)
            );
        }
    }

    /**
     * Tests that explicit text model IDs receive metadata without listing models.
     *
     * The returned metadata must be identical to the metadata the models endpoint parser produces
     * for the same ID, so that an explicitly named model behaves the same whether or not the
     * provider models list was fetched.
     *
     * @dataProvider explicitTextModelIdProvider
     *
     * @param string $modelId The explicit text model ID.
     */
    public function testExplicitTextModelIdsSkipModelListing(string $modelId): void
    {
        $explicitMetadata = $this->createModelMetadataForExplicitModelIds([$modelId]);

        $this->assertArrayHasKey($modelId, $explicitMetadata);
        $this->assertEquals($this->parseSingleModelMetadata($modelId), $explicitMetadata[$modelId]);
    }

    /**
     * Data provider for testExplicitTextModelIdsSkipModelListing().
     *
     * Model IDs are verified against a live OpenAI models response.
     *
     * @return array<string, array{string}> Map of test name to test data.
     */
    public function explicitTextModelIdProvider(): array
    {
        return [
            'gpt-5.6-sol' => ['gpt-5.6-sol'],
            'gpt-5.6-terra' => ['gpt-5.6-terra'],
            'gpt-5.5' => ['gpt-5.5'],
            'gpt-5.4-mini' => ['gpt-5.4-mini'],
            'gpt-5.1-codex' => ['gpt-5.1-codex'],
            'gpt-5-chat-latest' => ['gpt-5-chat-latest'],
        ];
    }

    /**
     * Tests that explicit non-text model IDs keep falling back to listing models.
     *
     * @dataProvider explicitNonTextModelIdProvider
     *
     * @param string $modelId The explicit model ID.
     */
    public function testExplicitNonTextModelIdsFallBackToModelListing(string $modelId): void
    {
        $this->assertSame([], $this->createModelMetadataForExplicitModelIds([$modelId]));
    }

    /**
     * Data provider for testExplicitNonTextModelIdsFallBackToModelListing().
     *
     * @return array<string, array{string}> Map of test name to test data.
     */
    public function explicitNonTextModelIdProvider(): array
    {
        return [
            'gpt-image-2' => ['gpt-image-2'],
            'chatgpt-image-latest' => ['chatgpt-image-latest'],
            'gpt-4o-mini-tts' => ['gpt-4o-mini-tts'],
            'text-embedding-3-small' => ['text-embedding-3-small'],
            'gpt-realtime-2' => ['gpt-realtime-2'],
            'gpt-4o-transcribe' => ['gpt-4o-transcribe'],
            'gpt-3.5-turbo-instruct' => ['gpt-3.5-turbo-instruct'],
            'unknown-model' => ['some-unknown-model'],
        ];
    }

    /**
     * Creates the explicit model metadata for the given model IDs.
     *
     * @param list<string> $modelIds The explicit model IDs.
     * @return array<string, ModelMetadata> Map of model ID to explicit model metadata.
     */
    private function createModelMetadataForExplicitModelIds(array $modelIds): array
    {
        $directory = new OpenAiModelMetadataDirectory();

        $method = new ReflectionMethod($directory, 'createModelMetadataForExplicitModelIds');
        $method->setAccessible(true);

        /** @var array<string, ModelMetadata> $modelMetadataMap */
        $modelMetadataMap = $method->invoke($directory, $modelIds);

        return $modelMetadataMap;
    }

    /**
     * Parses the model metadata for a single model ID via the models endpoint response parser.
     *
     * @param string $modelId The model ID.
     * @return ModelMetadata The parsed model metadata.
     */
    private function parseSingleModelMetadata(string $modelId): ModelMetadata
    {
        $directory = new OpenAiModelMetadataDirectory();

        $method = new ReflectionMethod($directory, 'parseResponseToModelMetadataList');
        $method->setAccessible(true);

        $response = new Response(200, [], (string) json_encode(['data' => [['id' => $modelId]]]));

        /** @var list<ModelMetadata> $modelMetadataList */
        $modelMetadataList = $method->invoke($directory, $response);

        $this->assertCount(1, $modelMetadataList);

        return $modelMetadataList[0];
    }

    /**
     * Returns the names of the supported options of the given model metadata.
     *
     * @param ModelMetadata $modelMetadata The model metadata.
     * @return list<string> The supported option names.
     */
    private function getSupportedOptionNames(ModelMetadata $modelMetadata): array
    {
        return array_map(
            static function (SupportedOption $supportedOption): string {
                return $supportedOption->getName()->value;
            },
            $modelMetadata->getSupportedOptions()
        );
    }
}
