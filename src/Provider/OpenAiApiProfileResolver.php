<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Provider;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\OpenAiAiProvider\Contracts\OpenAiApiProfileAwareAuthenticationInterface;
use WordPress\OpenAiAiProvider\Contracts\OpenAiApiProfileInterface;

/**
 * Resolves an alternate OpenAI API profile for request authentication.
 *
 * @since 1.1.0
 */
final class OpenAiApiProfileResolver
{
    /**
     * Resolves the API profile associated with request authentication.
     *
     * The resolver itself does not cache results. Provider objects pin the
     * result for their currently bound request authentication and resolve it
     * again when new authentication is bound. A profile is trusted code: it
     * can route authenticated requests to a different endpoint and must
     * validate that destination.
     *
     * @since 1.1.0
     *
     * @param RequestAuthenticationInterface $authentication The request authentication.
     * @return OpenAiApiProfileInterface|null The alternate profile, or null for the standard API.
     */
    public static function resolve(
        RequestAuthenticationInterface $authentication
    ): ?OpenAiApiProfileInterface {
        $profile = $authentication instanceof OpenAiApiProfileAwareAuthenticationInterface
            ? $authentication->getOpenAiApiProfile()
            : null;

        if (function_exists('apply_filters')) {
            /**
             * Filters the OpenAI API profile used with request authentication.
             *
             * A profile controls where authenticated requests are sent. Only
             * trusted code should return a profile from this filter.
             *
             * @since 1.1.0
             *
             * @param OpenAiApiProfileInterface|null $profile The resolved profile, if any.
             * @param RequestAuthenticationInterface $authentication The request authentication.
             */
            $profile = apply_filters(
                'ai_provider_for_openai_api_profile',
                $profile,
                $authentication
            );
        }

        if ($profile !== null && !$profile instanceof OpenAiApiProfileInterface) {
            throw new RuntimeException(
                'The OpenAI API profile must implement OpenAiApiProfileInterface or be null.'
            );
        }

        return $profile;
    }

    /**
     * This class only contains static methods and cannot be instantiated.
     */
    private function __construct()
    {
    }
}
