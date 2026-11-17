<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Traits;

use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\OpenAiAiProvider\Contracts\OpenAiApiProfileInterface;
use WordPress\OpenAiAiProvider\Provider\OpenAiApiProfileResolver;

/**
 * Pins an OpenAI API profile to the currently bound request authentication.
 *
 * Resolving once ensures every stage of an operation uses the same trusted
 * profile for its endpoint, request preparation, response normalization, and
 * cache namespace. Rebinding authentication clears the pinned profile.
 *
 * @since n.e.x.t
 */
trait OpenAiApiProfileContextTrait
{
    /**
     * The profile resolved for the bound request authentication.
     *
     * @var OpenAiApiProfileInterface|null
     */
    private ?OpenAiApiProfileInterface $openAiApiProfile = null;

    /**
     * Whether a profile has been resolved for the bound authentication.
     *
     * This flag distinguishes a resolved standard API profile (null) from a
     * context that has not been resolved yet.
     *
     * @var bool
     */
    private bool $openAiApiProfileResolved = false;

    /**
     * Whether request authentication has been explicitly bound.
     *
     * @var bool
     */
    private bool $openAiAuthenticationBound = false;

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function setRequestAuthentication(
        RequestAuthenticationInterface $requestAuthentication
    ): void {
        parent::setRequestAuthentication($requestAuthentication);

        $this->openAiAuthenticationBound = true;

        $this->openAiApiProfile = null;
        $this->openAiApiProfileResolved = false;
    }

    /**
     * Gets the profile pinned to the bound request authentication.
     *
     * @since n.e.x.t
     *
     * @return OpenAiApiProfileInterface|null The alternate profile, or null for the standard API.
     */
    protected function getOpenAiApiProfile(): ?OpenAiApiProfileInterface
    {
        // Parsing and cache-key inspection do not require credentials.
        if (!$this->openAiAuthenticationBound) {
            return null;
        }

        if (!$this->openAiApiProfileResolved) {
            $this->openAiApiProfile = OpenAiApiProfileResolver::resolve(
                $this->getRequestAuthentication()
            );
            $this->openAiApiProfileResolved = true;
        }

        return $this->openAiApiProfile;
    }
}
