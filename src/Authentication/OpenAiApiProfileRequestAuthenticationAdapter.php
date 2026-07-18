<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Authentication;

use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\OpenAiAiProvider\Contracts\OpenAiApiProfileAwareAuthenticationInterface;
use WordPress\OpenAiAiProvider\Contracts\OpenAiApiProfileInterface;

/**
 * Adapts profile-aware authentication to the PHP AI Client registry contract.
 *
 * The PHP AI Client currently declares API-key authentication as the OpenAI
 * provider's authentication method and therefore requires replacement
 * authentication to extend ApiKeyRequestAuthentication. This adapter keeps
 * that compatibility detail out of alternate authentication implementations.
 * It is a runtime-only adapter and should not be used as a serialized
 * credential value.
 *
 * @since 1.1.0
 */
final class OpenAiApiProfileRequestAuthenticationAdapter extends ApiKeyRequestAuthentication implements
    OpenAiApiProfileAwareAuthenticationInterface
{
    /**
     * The profile-aware authentication implementation.
     *
     * @var OpenAiApiProfileAwareAuthenticationInterface
     */
    private OpenAiApiProfileAwareAuthenticationInterface $authentication;

    /**
     * Constructor.
     *
     * @since 1.1.0
     *
     * @param OpenAiApiProfileAwareAuthenticationInterface $authentication Authentication to adapt.
     */
    public function __construct(OpenAiApiProfileAwareAuthenticationInterface $authentication)
    {
        /*
         * This value is never sent. authenticateRequest() always delegates to
         * the wrapped authentication implementation.
         */
        parent::__construct('openai-api-profile-managed-authentication');
        $this->authentication = $authentication;
    }

    /**
     * {@inheritDoc}
     */
    public function authenticateRequest(Request $request): Request
    {
        return $this->authentication->authenticateRequest($request);
    }

    /**
     * {@inheritDoc}
     */
    public function getOpenAiApiProfile(): OpenAiApiProfileInterface
    {
        return $this->authentication->getOpenAiApiProfile();
    }
}
