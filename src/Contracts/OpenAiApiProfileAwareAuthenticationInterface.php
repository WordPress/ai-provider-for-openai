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
 * @since n.e.x.t
 */
interface OpenAiApiProfileAwareAuthenticationInterface extends RequestAuthenticationInterface
{
    /**
     * Gets the API profile associated with this request authentication.
     *
     * @since n.e.x.t
     *
     * @return OpenAiApiProfileInterface The associated API profile.
     */
    public function getOpenAiApiProfile(): OpenAiApiProfileInterface;
}
