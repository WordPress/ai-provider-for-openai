<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Codex;

use RuntimeException;

/**
 * Refreshes ChatGPT/Codex OAuth access tokens.
 *
 * @since n.e.x.t
 */
class CodexOAuthClient
{
    private const TOKEN_URL = 'https://auth.openai.com/oauth/token';
    private const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    /**
     * @var CodexTokenStore Token store.
     */
    private CodexTokenStore $tokenStore;

    /**
     * Constructor.
     *
     * @since n.e.x.t
     *
     * @param CodexTokenStore $tokenStore Token store.
     */
    public function __construct(CodexTokenStore $tokenStore)
    {
        $this->tokenStore = $tokenStore;
    }

    /**
     * Gets a fresh access token.
     *
     * @since n.e.x.t
     *
     * @return string Access token.
     * @throws RuntimeException If OAuth refresh fails.
     */
    public function getAccessToken(): string
    {
        $accessToken = $this->tokenStore->getAccessToken();
        if ($accessToken !== null) {
            return $accessToken;
        }

        $tokens = $this->tokenStore->getTokens();
        $refreshToken = $tokens['refresh_token'] ?? '';
        if ($refreshToken === '') {
            throw new RuntimeException('Codex OAuth refresh token is not configured.');
        }

        $data = $this->refreshAccessToken($refreshToken);
        if (empty($data['access_token']) || !is_scalar($data['access_token'])) {
            throw new RuntimeException('Codex OAuth refresh returned an invalid response.');
        }

        $updated = array_merge(
            $tokens,
            [
                'access_token' => (string) $data['access_token'],
                'expires_at' => time() + $this->getIntegerValue($data['expires_in'] ?? null, 3600),
            ]
        );

        if (!empty($data['refresh_token']) && is_scalar($data['refresh_token'])) {
            $updated['refresh_token'] = (string) $data['refresh_token'];
        }

        $this->tokenStore->updateTokens($updated);
        return (string) $data['access_token'];
    }

    /**
     * Refreshes an access token.
     *
     * @since n.e.x.t
     *
     * @param string $refreshToken Refresh token.
     * @return array<string, mixed> Response data.
     * @throws RuntimeException If the request fails.
     */
    private function refreshAccessToken(string $refreshToken): array
    {
        $body = http_build_query(
            [
                'grant_type' => 'refresh_token',
                'client_id' => self::CLIENT_ID,
                'refresh_token' => $refreshToken,
            ]
        );

        $context = stream_context_create(
            [
                    'http' => [
                        'method' => 'POST',
                        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                        'content' => $body,
                        'ignore_errors' => true,
                        'timeout' => 20,
                    ],
                ]
        );
        $responseBody = file_get_contents(self::TOKEN_URL, false, $context);
        if ($responseBody === false) {
            throw new RuntimeException('Codex OAuth refresh failed.');
        }

        $data = json_decode($responseBody, true);
        if (!is_array($data)) {
            throw new RuntimeException('Codex OAuth refresh returned an invalid response.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Gets an integer value with a fallback.
     *
     * @since n.e.x.t
     *
     * @param mixed $value Raw value.
     * @param int $fallback Fallback value.
     * @return int Integer value.
     */
    private function getIntegerValue($value, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        return (int) $value;
    }
}
