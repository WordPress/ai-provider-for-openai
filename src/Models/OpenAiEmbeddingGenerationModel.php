<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Models;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleEmbeddingGenerationModel;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

/**
 * Class for an OpenAI embedding generation model using the Embeddings API.
 *
 * @since n.e.x.t
 */
class OpenAiEmbeddingGenerationModel extends AbstractOpenAiCompatibleEmbeddingGenerationModel
{
    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
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
}
