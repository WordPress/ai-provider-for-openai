<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Embedding;
use WordPress\AiClient\Results\DTO\EmbeddingResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

/**
 * Class for an OpenAI embedding generation model using the Embeddings API.
 *
 * @since n.e.x.t
 *
 * @phpstan-type EmbeddingData array{embedding?: list<float|int>, index?: int}
 * @phpstan-type UsageData array{prompt_tokens?: int, total_tokens?: int}
 * @phpstan-type ResponseData array{id?: string, data?: list<EmbeddingData>, usage?: UsageData}
 */
class OpenAiEmbeddingGenerationModel extends AbstractApiBasedModel implements EmbeddingGenerationModelInterface
{
    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     *
     * @param list<list<Message>> $input The prompts to generate embeddings for, one message list per prompt.
     * @return EmbeddingResult The embedding result.
     */
    public function generateEmbeddingResult(array $input): EmbeddingResult
    {
        $params = $this->prepareGenerateEmbeddingsParams($input);

        $request = $this->createRequest(
            HttpMethodEnum::POST(),
            'embeddings',
            ['Content-Type' => 'application/json'],
            $params
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseResponseToEmbeddingResult($response);
    }

    /**
     * Prepares the given prompts and model configuration into API request parameters.
     *
     * @since n.e.x.t
     *
     * @param list<list<Message>> $input The prompts to generate embeddings for, one message list per prompt.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateEmbeddingsParams(array $input): array
    {
        if (!array_is_list($input)) {
            throw new InvalidArgumentException('Embedding input must be provided as a list of prompts.');
        }

        if (empty($input)) {
            throw new InvalidArgumentException('The API requires at least one prompt.');
        }

        $preparedInput = [];
        foreach ($input as $messages) {
            $preparedInput[] = $this->preparePromptInput($messages);
        }

        $params = [
            'model' => $this->metadata()->getId(),
            'input' => $preparedInput,
        ];

        $dimensions = $this->getConfig()->getDimensions();
        if ($dimensions !== null) {
            $params['dimensions'] = $dimensions;
        }

        foreach ($this->getConfig()->getCustomOptions() as $key => $value) {
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The custom option "%s" conflicts with an existing parameter.',
                        $key
                    )
                );
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Prepares a single prompt (a list of messages) into one embeddings input string.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $messages The messages that make up one embedding input.
     * @return string The prompt text.
     */
    protected function preparePromptInput(array $messages): string
    {
        if (!array_is_list($messages) || empty($messages)) {
            throw new InvalidArgumentException('Each embedding prompt must be a non-empty list of messages.');
        }

        $textParts = [];
        foreach ($messages as $message) {
            $textParts[] = $this->prepareMessageInput($message);
        }

        return implode("\n", $textParts);
    }

    /**
     * Prepares a single message for the embeddings input parameter.
     *
     * @since n.e.x.t
     *
     * @param Message $message The message for one embedding input.
     * @return string The prompt text.
     */
    protected function prepareMessageInput(Message $message): string
    {
        $textParts = [];
        foreach ($message->getParts() as $part) {
            $text = $part->getText();
            if ($text !== null) {
                $textParts[] = $text;
            }
        }

        if (empty($textParts)) {
            throw new InvalidArgumentException('The API requires text content to generate embeddings.');
        }

        return implode("\n", $textParts);
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     *
     * @param HttpMethodEnum                    $method The HTTP method.
     * @param string                            $path The request path.
     * @param array<string, list<string>|string> $headers The request headers.
     * @param array<string, mixed>|string|null  $data The request body data.
     * @return Request The request.
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        return new Request(
            $method,
            OpenAiProvider::url($path),
            $headers,
            $data,
            $this->getRequestOptions()
        );
    }

    /**
     * Parses the response from the API endpoint to an embedding result.
     *
     * @since n.e.x.t
     *
     * @param Response $response The response from the API endpoint.
     * @return EmbeddingResult The parsed embedding result.
     */
    protected function parseResponseToEmbeddingResult(Response $response): EmbeddingResult
    {
        /** @var ResponseData $responseData */
        $responseData = $response->getData();

        if (!isset($responseData['data']) || !$responseData['data']) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'data');
        }
        if (!is_array($responseData['data']) || !array_is_list($responseData['data'])) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'data',
                'The value must be an indexed array.'
            );
        }

        $embeddings = [];
        foreach ($responseData['data'] as $index => $embeddingData) {
            if (
                !is_array($embeddingData) ||
                !isset($embeddingData['embedding']) ||
                !is_array($embeddingData['embedding'])
            ) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "data[{$index}].embedding",
                    'The value must be an embedding vector.'
                );
            }
            $embeddings[] = new Embedding(
                $embeddingData['embedding'],
                count($embeddingData['embedding'])
            );
        }

        $usage = isset($responseData['usage']) && is_array($responseData['usage']) ? $responseData['usage'] : [];
        $tokenUsage = new TokenUsage(
            $usage['prompt_tokens'] ?? 0,
            0,
            $usage['total_tokens'] ?? ($usage['prompt_tokens'] ?? 0)
        );

        $additionalData = $responseData;
        unset($additionalData['id'], $additionalData['data'], $additionalData['usage']);

        return new EmbeddingResult(
            isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '',
            $embeddings,
            count($embeddings[0]->getValues()),
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }
}
