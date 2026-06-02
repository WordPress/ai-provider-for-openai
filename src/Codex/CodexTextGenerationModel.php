<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Codex;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Text generation model for ChatGPT Codex using the Codex Responses endpoint.
 *
 * @since n.e.x.t
 */
class CodexTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    private const REQUEST_TIMEOUT_FLOOR = 300.0;
    private const CONNECT_TIMEOUT_FLOOR = 120.0;

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $request = new Request(
            HttpMethodEnum::POST(),
            CodexProvider::url('responses'),
            ['Content-Type' => 'application/json'],
            $this->prepareGenerateTextParams($prompt),
            $this->getCodexRequestOptions()
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseResponseToResult($response);
    }

    private function getCodexRequestOptions(): RequestOptions
    {
        $current = $this->getRequestOptions();
        $options = new RequestOptions();

        $timeout = $current?->getTimeout();
        $timeout = max($timeout ?? 0.0, self::REQUEST_TIMEOUT_FLOOR);
        $options->setTimeout($timeout);

        $connectTimeout = $current?->getConnectTimeout();
        $connectTimeoutFloor = min(self::CONNECT_TIMEOUT_FLOOR, $timeout);
        $options->setConnectTimeout(max($connectTimeout ?? 0.0, $connectTimeoutFloor));

        $maxRedirects = $current?->getMaxRedirects();
        if ($maxRedirects !== null) {
            $options->setMaxRedirects($maxRedirects);
        }

        return $options;
    }

    /**
     * Prepares the request payload.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $prompt Prompt messages.
     * @return array<string, mixed> Request payload.
     */
    private function prepareGenerateTextParams(array $prompt): array
    {
        $config = $this->getConfig();
        $params = [
            'model' => $this->metadata()->getId(),
            'input' => $this->prepareInput($prompt),
            'instructions' => $config->getSystemInstruction() ?: 'You are a concise coding assistant.',
            'store' => false,
            'stream' => true,
        ];

        $maxTokens = $config->getMaxTokens();
        if ($maxTokens !== null) {
            $params['max_output_tokens'] = $maxTokens;
        }

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $params['temperature'] = $temperature;
        }

        $topP = $config->getTopP();
        if ($topP !== null) {
            $params['top_p'] = $topP;
        }

        if ($config->getOutputMimeType() === 'application/json' && $config->getOutputSchema()) {
            $params['text'] = [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'response_schema',
                    'schema' => $config->getOutputSchema(),
                    'strict' => true,
                ],
            ];
        }

        foreach ($config->getCustomOptions() as $key => $value) {
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf('The custom option "%s" conflicts with an existing Codex request parameter.', $key)
                );
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Converts prompt messages to Codex Responses input items.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $messages Prompt messages.
     * @return list<array<string, mixed>> Input items.
     */
    private function prepareInput(array $messages): array
    {
        $input = [];
        foreach ($messages as $message) {
            $content = [];
            foreach ($message->getParts() as $part) {
                if (!$part->getType()->isText()) {
                    throw new InvalidArgumentException(
                        'Codex text generation currently supports text message parts only.'
                    );
                }
                $content[] = [
                    'type' => $message->getRole()->isModel() ? 'output_text' : 'input_text',
                    'text' => $part->getText(),
                ];
            }

            $input[] = [
                'role' => $this->roleToResponsesRole($message->getRole()),
                'content' => $content,
            ];
        }

        return $input;
    }

    /**
     * Converts a client role to a Responses API role.
     *
     * @since n.e.x.t
     *
     * @param MessageRoleEnum $role Message role.
     * @return string Responses API role.
     */
    private function roleToResponsesRole(MessageRoleEnum $role): string
    {
        return $role->isModel() ? 'assistant' : 'user';
    }

    /**
     * Parses an API response into a result.
     *
     * @since n.e.x.t
     *
     * @param Response $response API response.
     * @return GenerativeAiResult Result.
     */
    private function parseResponseToResult(Response $response): GenerativeAiResult
    {
        $data = $response->getData();
        if (!is_array($data)) {
            $data = $this->parseSseResponse((string) $response->getBody());
        }

        $text = '';
        if (isset($data['output_text']) && is_string($data['output_text'])) {
            $text = $data['output_text'];
        } elseif (isset($data['output']) && is_array($data['output'])) {
            /** @var array<int, mixed> $output */
            $output = $data['output'];
            $text = $this->extractOutputText($output);
        }

        if ($text === '') {
            throw ResponseException::fromMissingData('ChatGPT Codex', 'output_text');
        }

        $usage = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
        $inputTokens = $this->getIntegerValue($usage['input_tokens'] ?? null);
        $outputTokens = $this->getIntegerValue($usage['output_tokens'] ?? null);

        return new GenerativeAiResult(
            isset($data['id']) && is_string($data['id']) ? $data['id'] : '',
            [new Candidate(new ModelMessage([new MessagePart($text)]), FinishReasonEnum::stop())],
            new TokenUsage(
                $inputTokens,
                $outputTokens,
                $this->getIntegerValue($usage['total_tokens'] ?? null, $inputTokens + $outputTokens)
            ),
            $this->providerMetadata(),
            $this->metadata(),
            $data
        );
    }

    /**
     * Parses a buffered text/event-stream response body.
     *
     * @since n.e.x.t
     *
     * @param string $body Response body.
     * @return array<string, mixed> Parsed response data.
     */
    private function parseSseResponse(string $body): array
    {
        $text = '';
        /** @var array<string, mixed> $completed */
        $completed = [];
        $eventData = '';
        $lines = preg_split("/\r\n|\n|\r/", $body);

        if ($lines === false) {
            $lines = [];
        }

        foreach ($lines as $line) {
            if (trim($line) === '') {
                $this->consumeSseEventData($eventData, $text, $completed);
                $eventData = '';
                continue;
            }

            if (substr($line, 0, 6) === 'data: ') {
                $eventData .= substr($line, 6);
            }
        }

        $this->consumeSseEventData($eventData, $text, $completed);

        if ($text !== '') {
            $completed['output_text'] = $text;
        }

        return $completed;
    }

    /**
     * Consumes one SSE event data payload.
     *
     * @since n.e.x.t
     *
     * @param string $eventData Event data.
     * @param string $text Accumulated text.
     * @param array<string, mixed> $completed Completed response data.
     * @return void
     */
    private function consumeSseEventData(string $eventData, string &$text, array &$completed): void
    {
        if ($eventData === '' || $eventData === '[DONE]') {
            return;
        }

        $data = json_decode($eventData, true);
        if (!is_array($data)) {
            return;
        }

        if (
            ($data['type'] ?? '') === 'response.output_text.delta' &&
            isset($data['delta']) &&
            is_string($data['delta'])
        ) {
            $text .= $data['delta'];
        } elseif (isset($data['delta']) && is_string($data['delta'])) {
            $text .= $data['delta'];
        }

        if (isset($data['response']) && is_array($data['response']) && ($data['type'] ?? '') === 'response.completed') {
            /** @var array<string, mixed> $response */
            $response = $data['response'];
            $completed = $response;
        }
    }

    /**
     * Extracts output text from Responses API output items.
     *
     * @since n.e.x.t
     *
     * @param array<int, mixed> $output Output items.
     * @return string Output text.
     */
    private function extractOutputText(array $output): string
    {
        $parts = [];
        foreach ($output as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'message' || !isset($item['content'])) {
                continue;
            }
            if (!is_array($item['content'])) {
                continue;
            }

            foreach ($item['content'] as $content) {
                if (is_array($content) && ($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                    if (is_string($content['text'])) {
                        $parts[] = $content['text'];
                    }
                }
            }
        }

        return implode("\n", $parts);
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
    private function getIntegerValue($value, int $fallback = 0): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        return (int) $value;
    }
}
