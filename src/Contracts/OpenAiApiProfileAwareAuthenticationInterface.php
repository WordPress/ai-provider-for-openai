<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Contracts;

use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;

/**
 * Identifies request authentication that uses an alternate OpenAI API profile.
 *
 * Coupling the profile to its authentication object prevents the provider from
 * inferring an API dialect from a concrete authentication implementation.
 *
 * @since 1.1.0
 */
interface OpenAiApiProfileAwareAuthenticationInterface extends RequestAuthenticationInterface
{
    /**
     * Gets the API profile associated with this request authentication.
     *
     * @since 1.1.0
     *
     * @return OpenAiApiProfileInterface The associated API profile.
     */
    public function getOpenAiApiProfile(): OpenAiApiProfileInterface;
}
