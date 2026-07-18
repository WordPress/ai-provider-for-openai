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
 * @since 1.1.0
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
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function setRequestAuthentication(
        RequestAuthenticationInterface $requestAuthentication
    ): void {
        parent::setRequestAuthentication($requestAuthentication);

        $this->openAiApiProfile = null;
        $this->openAiApiProfileResolved = false;
    }

    /**
     * Gets the profile pinned to the bound request authentication.
     *
     * @since 1.1.0
     *
     * @return OpenAiApiProfileInterface|null The alternate profile, or null for the standard API.
     */
    protected function getOpenAiApiProfile(): ?OpenAiApiProfileInterface
    {
        if (!$this->openAiApiProfileResolved) {
            $this->openAiApiProfile = OpenAiApiProfileResolver::resolve(
                $this->getRequestAuthentication()
            );
            $this->openAiApiProfileResolved = true;
        }

        return $this->openAiApiProfile;
    }
}
