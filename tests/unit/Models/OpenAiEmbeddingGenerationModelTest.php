<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Tests\unit\Models;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\OpenAiAiProvider\Models\OpenAiEmbeddingGenerationModel;

/**
 * @covers \WordPress\OpenAiAiProvider\Models\OpenAiEmbeddingGenerationModel
 */
class OpenAiEmbeddingGenerationModelTest extends TestCase
{
    /**
     * Skips the tests unless the installed PHP AI Client supports embedding generation.
     *
     * Embedding generation support was added in PHP AI Client 1.4.0, while the plugin
     * still supports 1.3.1 as bundled with WordPress 7.0.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!interface_exists(EmbeddingGenerationModelInterface::class)) {
            $this->markTestSkipped('Embedding generation requires PHP AI Client 1.4.0 or later.');
        }
    }

    public function testGenerateEmbeddingResultSendsEmbeddingsApiRequest(): void
    {
        $model = new class(
            $this->createModelMetadata(),
            $this->createProviderMetadata()
        ) extends OpenAiEmbeddingGenerationModel {
            public function exposePrepareGenerateEmbeddingsParams(array $input): array
            {
                return $this->prepareGenerateEmbeddingsParams($input);
            }
        };

        $model->setConfig(ModelConfig::fromArray([
            'dimensions' => 3,
            'customOptions' => ['encoding_format' => 'float'],
        ]));
        $params = $model->exposePrepareGenerateEmbeddingsParams([new MessagePart('Search text')]);

        $this->assertEquals('text-embedding-3-small', $params['model']);
        $this->assertEquals(['Search text'], $params['input']);
        $this->assertEquals(3, $params['dimensions']);
        $this->assertEquals('float', $params['encoding_format']);
    }

    public function testGenerateEmbeddingResultSendsBatchEmbeddingsApiRequest(): void
    {
        $model = new class(
            $this->createModelMetadata(),
            $this->createProviderMetadata()
        ) extends OpenAiEmbeddingGenerationModel {
            public function exposePrepareGenerateEmbeddingsParams(array $input): array
            {
                return $this->prepareGenerateEmbeddingsParams($input);
            }
        };

        $params = $model->exposePrepareGenerateEmbeddingsParams([
            new MessagePart('First'),
            new MessagePart('Second'),
        ]);

        $this->assertEquals(['First', 'Second'], $params['input']);
    }

    /**
     * @dataProvider invalidInputs
     *
     * @param array<mixed> $input The invalid embedding input.
     * @param string       $message The expected exception message.
     */
    public function testPrepareGenerateEmbeddingsParamsRejectsInvalidInputs(array $input, string $message): void
    {
        $model = new class(
            $this->createModelMetadata(),
            $this->createProviderMetadata()
        ) extends OpenAiEmbeddingGenerationModel {
            public function exposePrepareGenerateEmbeddingsParams(array $input): array
            {
                return $this->prepareGenerateEmbeddingsParams($input);
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $model->exposePrepareGenerateEmbeddingsParams($input);
    }

    /**
     * @return array<string, array{0: array<mixed>, 1: string}>
     */
    public function invalidInputs(): array
    {
        return [
            'empty list' => [[], 'The API requires at least one prompt.'],
            'non-list array' => [['first' => new MessagePart('Search text')], 'list of message parts'],
            'non-message part' => [[1], 'index 0 must be a MessagePart'],
            'file part' => [[new MessagePart(new File('https://example.com/image.jpg', 'image/jpeg'))], 'index 0 must be a text part'],
            'blank text part' => [[new MessagePart('   ')], 'index 0 must contain non-empty text'],
        ];
    }

    public function testGenerateEmbeddingResultSendsRequestAndParsesBatchResponse(): void
    {
        $model = new OpenAiEmbeddingGenerationModel(
            $this->createModelMetadata(),
            $this->createProviderMetadata()
        );
        $httpTransporter = $this->createMock(HttpTransporterInterface::class);
        $requestAuthentication = $this->createMock(RequestAuthenticationInterface::class);

        $requestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $httpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (Request $request): Response {
                $this->assertSame('text-embedding-3-small', $request->getData()['model']);
                $this->assertSame(['First', 'Second'], $request->getData()['input']);
                return new Response(
                    200,
                    [],
                    json_encode([
                        'id' => 'emb-openai-123',
                        'data' => [
                            ['embedding' => [0.1, 0.2, 0.3], 'index' => 0],
                            ['embedding' => [0.4, 0.5, 0.6], 'index' => 1],
                        ],
                        'usage' => [
                            'prompt_tokens' => 2,
                            'total_tokens' => 2,
                        ],
                    ])
                );
            });

        $model->setHttpTransporter($httpTransporter);
        $model->setRequestAuthentication($requestAuthentication);

        $result = $model->generateEmbeddingResult([
            new MessagePart('First'),
            new MessagePart('Second'),
        ]);

        $this->assertEquals('emb-openai-123', $result->getId());
        $this->assertEquals([0.1, 0.2, 0.3], $result->getEmbeddings()[0]->getValues());
        $this->assertEquals([0.4, 0.5, 0.6], $result->getEmbeddings()[1]->getValues());
        $this->assertCount(2, $result->getEmbeddings());
        $this->assertEquals(2, $result->getTokenUsage()->getPromptTokens());
    }

    private function createModelMetadata(): ModelMetadata
    {
        return new ModelMetadata(
            'text-embedding-3-small',
            'text-embedding-3-small',
            [CapabilityEnum::embeddingGeneration()],
            []
        );
    }

    private function createProviderMetadata(): ProviderMetadata
    {
        return new ProviderMetadata('openai', 'OpenAI', ProviderTypeEnum::cloud());
    }
}
