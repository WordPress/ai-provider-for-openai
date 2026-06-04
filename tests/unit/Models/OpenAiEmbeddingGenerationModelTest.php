<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Tests\unit\Models;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\OpenAiAiProvider\Models\OpenAiEmbeddingGenerationModel;

/**
 * @covers \WordPress\OpenAiAiProvider\Models\OpenAiEmbeddingGenerationModel
 */
class OpenAiEmbeddingGenerationModelTest extends TestCase
{
    public function testGenerateEmbeddingResultSendsEmbeddingsApiRequest(): void
    {
        $model = new class(
            $this->createModelMetadata(),
            $this->createProviderMetadata()
        ) extends OpenAiEmbeddingGenerationModel {
            public function exposePrepareGenerateEmbeddingParams(array $prompt): array
            {
                return $this->prepareGenerateEmbeddingParams($prompt);
            }
        };

        $model->setConfig(ModelConfig::fromArray(['dimensions' => 3]));
        $params = $model->exposePrepareGenerateEmbeddingParams([
            new Message(MessageRoleEnum::user(), [new MessagePart('Search text')]),
        ]);

        $this->assertEquals('text-embedding-3-small', $params['model']);
        $this->assertEquals('Search text', $params['input']);
        $this->assertEquals(3, $params['dimensions']);
    }

    public function testGenerateEmbeddingResultParsesResponse(): void
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
            ->willReturn(new Response(
                200,
                [],
                json_encode([
                    'id' => 'emb-openai-123',
                    'data' => [
                        ['embedding' => [0.1, 0.2, 0.3], 'index' => 0],
                    ],
                    'usage' => [
                        'prompt_tokens' => 2,
                        'total_tokens' => 2,
                    ],
                ])
            ));

        $model->setHttpTransporter($httpTransporter);
        $model->setRequestAuthentication($requestAuthentication);

        $result = $model->generateEmbeddingResult([
            new Message(MessageRoleEnum::user(), [new MessagePart('Search text')]),
        ]);

        $this->assertEquals('emb-openai-123', $result->getId());
        $this->assertEquals([[0.1, 0.2, 0.3]], $result->getEmbeddings());
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
