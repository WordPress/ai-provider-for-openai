<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Contracts;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Describes an alternate OpenAI API dialect for a request authentication type.
 *
 * Profiles may adapt endpoints, payloads, and responses while the provider
 * continues to own the common model behavior. Implementations are trusted
 * code because they can select the destination for authenticated requests.
 *
 * @since n.e.x.t
 */
interface OpenAiApiProfileInterface
{
    /**
     * Gets a stable, non-secret value used to separate model metadata caches.
     *
     * The provider hashes this value before using it in a cache key.
     *
     * @since n.e.x.t
     *
     * @return string The profile cache identity.
     */
    public function getCacheKey(): string;

    /**
     * Checks whether the profile supports an OpenAI provider operation.
     *
     * LIST_MODELS is required for provider availability and model discovery.
     * Returning false for it makes the provider unavailable on a cache miss.
     *
     * @since n.e.x.t
     *
     * @param string $operation One of the OpenAiApiOperation constants.
     * @return bool True if the operation is supported, false otherwise.
     */
    public function supportsOperation(string $operation): bool;

    /**
     * Gets the endpoint URL for an OpenAI provider operation.
     *
     * Implementations must only return destinations that they trust to receive
     * credentials from the associated request authentication object.
     *
     * @since n.e.x.t
     *
     * @param string $defaultUrl The URL used by the standard OpenAI API profile.
     * @param string $path The endpoint path relative to the API base URL.
     * @param string $operation One of the OpenAiApiOperation constants.
     * @return string The endpoint URL to request.
     */
    public function getRequestUrl(string $defaultUrl, string $path, string $operation): string;

    /**
     * Adapts a request before authentication credentials are applied.
     *
     * @since n.e.x.t
     *
     * @param Request $request The standard OpenAI API request.
     * @param string $operation One of the OpenAiApiOperation constants.
     * @return Request The adapted request.
     */
    public function prepareRequest(Request $request, string $operation): Request;

    /**
     * Normalizes a successful response before the standard response parser runs.
     *
     * @since n.e.x.t
     *
     * @param Response $response The successful response to normalize.
     * @param string $operation One of the OpenAiApiOperation constants.
     * @return Response The normalized response.
     */
    public function normalizeResponse(Response $response, string $operation): Response;

    /**
     * Parses a profile-specific list-models response.
     *
     * @since n.e.x.t
     *
     * @param Response $response The successful list-models response.
     * Return null to use the provider's standard OpenAI model metadata
     * parser after response normalization.
     *
     * @return list<ModelMetadata>|null The available model metadata, or null to use the standard parser.
     */
    public function parseModelMetadataList(Response $response): ?array;
}
