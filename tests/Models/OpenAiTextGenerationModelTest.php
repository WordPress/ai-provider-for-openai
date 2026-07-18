<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Tests\Models;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\OpenAiAiProvider\Models\OpenAiTextGenerationModel;

/**
 * Tests for the OpenAI text generation model.
 *
 * @since 1.0.4
 */
class OpenAiTextGenerationModelTest extends TestCase
{
    /**
     * Tests that native log-probability options are mapped to Responses API parameters.
     *
     * @since 1.1.0
     */
    public function testNativeLogProbabilityOptionsAreMapped(): void
    {
        $params = $this->prepareParams([
            'logprobs' => true,
            'topLogprobs' => 5,
        ]);

        $this->assertSame(['message.output_text.logprobs'], $params['include']);
        $this->assertSame(5, $params['top_logprobs']);
    }

    /**
     * Tests that explicitly disabling log probabilities does not request their output.
     *
     * @since 1.1.0
     */
    public function testFalseLogprobsDoesNotRequestLogProbabilityOutput(): void
    {
        $params = $this->prepareParams([
            'logprobs' => false,
        ]);

        $this->assertArrayNotHasKey('include', $params);
    }

    /**
     * Tests that sampling options combined with an explicit non-`none` reasoning effort are rejected.
     *
     * Models like `gpt-5.2` and `gpt-5.4` support sampling options in their default
     * `reasoning.effort: 'none'` mode, so the options are advertised in the model metadata.
     * When a request explicitly enables reasoning, the OpenAI API would reject the sampling
     * parameters, so the invalid combination must be caught before the request is sent.
     *
     * @dataProvider conflictingSamplingConfigProvider
     *
     * @param array<string, mixed> $configData The model configuration data.
     * @param string $expectedParam The sampling parameter expected to be reported.
     */
    public function testSamplingParamsWithExplicitReasoningEffortAreRejected(
        array $configData,
        string $expectedParam
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/"' . preg_quote($expectedParam, '/') . '".*reasoning effort/'
        );

        $this->prepareParams($configData);
    }

    /**
     * Data provider for testSamplingParamsWithExplicitReasoningEffortAreRejected().
     *
     * @return array<string, array{array<string, mixed>, string}> Test cases.
     */
    public static function conflictingSamplingConfigProvider(): array
    {
        return [
            'temperature with high effort' => [
                [
                    'temperature' => 0.7,
                    'customOptions' => ['reasoning' => ['effort' => 'high']],
                ],
                'temperature',
            ],
            'top_p with medium effort' => [
                [
                    'topP' => 0.9,
                    'customOptions' => ['reasoning' => ['effort' => 'medium']],
                ],
                'top_p',
            ],
            'logprobs with low effort' => [
                [
                    'logprobs' => true,
                    'customOptions' => ['reasoning' => ['effort' => 'low']],
                ],
                'logprobs',
            ],
            'top_logprobs with high effort' => [
                [
                    'topLogprobs' => 5,
                    'customOptions' => ['reasoning' => ['effort' => 'high']],
                ],
                'top_logprobs',
            ],
        ];
    }

    /**
     * Tests that sampling options are allowed when the reasoning effort is explicitly `none`.
     */
    public function testSamplingParamsWithReasoningEffortNoneAreAllowed(): void
    {
        $params = $this->prepareParams([
            'temperature' => 0.7,
            'topP' => 0.9,
            'logprobs' => true,
            'topLogprobs' => 5,
            'customOptions' => ['reasoning' => ['effort' => 'none']],
        ]);

        $this->assertSame(0.7, $params['temperature']);
        $this->assertSame(0.9, $params['top_p']);
        $this->assertSame(['message.output_text.logprobs'], $params['include']);
        $this->assertSame(5, $params['top_logprobs']);
        $this->assertSame(['effort' => 'none'], $params['reasoning']);
    }

    /**
     * Tests that sampling options are allowed when no reasoning effort is configured.
     */
    public function testSamplingParamsWithoutReasoningEffortAreAllowed(): void
    {
        $params = $this->prepareParams([
            'temperature' => 0.7,
        ]);

        $this->assertSame(0.7, $params['temperature']);
        $this->assertArrayNotHasKey('reasoning', $params);
    }

    /**
     * Tests that an explicit reasoning effort without sampling options is allowed.
     */
    public function testReasoningEffortWithoutSamplingParamsIsAllowed(): void
    {
        $params = $this->prepareParams([
            'customOptions' => ['reasoning' => ['effort' => 'high']],
        ]);

        $this->assertSame(['effort' => 'high'], $params['reasoning']);
        $this->assertArrayNotHasKey('temperature', $params);
        $this->assertArrayNotHasKey('top_p', $params);
    }

    /**
     * Prepares request parameters for a `gpt-5.2` model with the given configuration.
     *
     * @param array<string, mixed> $configData The model configuration data.
     * @return array<string, mixed> The prepared request parameters.
     */
    private function prepareParams(array $configData): array
    {
        $model = new OpenAiTextGenerationModel(
            new ModelMetadata('gpt-5.2', 'gpt-5.2', [CapabilityEnum::textGeneration()], []),
            new ProviderMetadata('openai', 'OpenAI', ProviderTypeEnum::cloud())
        );
        $model->setConfig(ModelConfig::fromArray($configData));

        $method = new ReflectionMethod($model, 'prepareGenerateTextParams');
        $method->setAccessible(true);

        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];

        /** @var array<string, mixed> $params */
        $params = $method->invoke($model, $prompt);

        return $params;
    }
}
