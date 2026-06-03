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
use WordPress\AiClient\Tools\DTO\FunctionCall;

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

        $timeout = $current ? $current->getTimeout() : null;
        $timeout = max($timeout ?? 0.0, self::REQUEST_TIMEOUT_FLOOR);
        $options->setTimeout($timeout);

        $connectTimeout = $current ? $current->getConnectTimeout() : null;
        $connectTimeoutFloor = min(self::CONNECT_TIMEOUT_FLOOR, $timeout);
        $options->setConnectTimeout(max($connectTimeout ?? 0.0, $connectTimeoutFloor));

        $maxRedirects = $current ? $current->getMaxRedirects() : null;
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

        $functionDeclarations = $config->getFunctionDeclarations();
        if (is_array($functionDeclarations)) {
            $params['tools'] = $this->prepareToolsParam($functionDeclarations);
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
        $this->validateMessages($messages);

        $input = [];
        foreach ($messages as $message) {
            $inputItem = $this->getMessageInputItem($message);
            if ($inputItem !== null) {
                $input[] = $inputItem;
            }
        }

        return $input;
    }

    /**
     * Validates Responses API message shape.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $messages Prompt messages.
     * @return void
     */
    private function validateMessages(array $messages): void
    {
        foreach ($messages as $message) {
            $parts = $message->getParts();
            if (count($parts) <= 1) {
                continue;
            }

            foreach ($parts as $part) {
                $type = $part->getType();
                if ($type->isFunctionCall() || $type->isFunctionResponse()) {
                    throw new InvalidArgumentException(
                        'Function call and function response parts must be the only part in a message for the Codex '
                        . 'Responses API.'
                    );
                }
            }
        }
    }

    /**
     * Converts a message to a Codex Responses input item.
     *
     * @since n.e.x.t
     *
     * @param Message $message Prompt message.
     * @return array<string, mixed>|null Input item, or null for an empty message.
     */
    private function getMessageInputItem(Message $message): ?array
    {
        $parts = $message->getParts();
        if (empty($parts)) {
            return null;
        }

        $content = [];
        foreach ($parts as $part) {
            $partData = $this->getMessagePartData($part, $message->getRole());
            $partType = $partData['type'] ?? '';
            if ($partType === 'function_call' || $partType === 'function_call_output') {
                return $partData;
            }
            $content[] = $partData;
        }

        return [
            'role' => $this->roleToResponsesRole($message->getRole()),
            'content' => $content,
        ];
    }

    /**
     * Converts a message part to a Codex Responses input part.
     *
     * @since n.e.x.t
     *
     * @param MessagePart     $part Message part.
     * @param MessageRoleEnum $role Message role.
     * @return array<string, mixed> Input part.
     */
    private function getMessagePartData(MessagePart $part, MessageRoleEnum $role): array
    {
        $type = $part->getType();
        if ($type->isText()) {
            return [
                'type' => $role->isModel() ? 'output_text' : 'input_text',
                'text' => $part->getText(),
            ];
        }

        if ($type->isFunctionCall()) {
            $functionCall = $part->getFunctionCall();
            if (!$functionCall) {
                throw new InvalidArgumentException(
                    'The function_call typed message part must contain a function call.'
                );
            }

            return [
                'type' => 'function_call',
                'call_id' => $functionCall->getId(),
                'name' => $functionCall->getName(),
                'arguments' => json_encode($functionCall->getArgs()),
            ];
        }

        if ($type->isFunctionResponse()) {
            $functionResponse = $part->getFunctionResponse();
            if (!$functionResponse) {
                throw new InvalidArgumentException(
                    'The function_response typed message part must contain a function response.'
                );
            }

            return [
                'type' => 'function_call_output',
                'call_id' => $functionResponse->getId(),
                'output' => json_encode($functionResponse->getResponse()),
            ];
        }

        throw new InvalidArgumentException(
            'Codex text generation currently supports text and function message parts only.'
        );
    }

    /**
     * Prepares Codex Responses tool declarations.
     *
     * @since n.e.x.t
     *
     * @param array<int, mixed> $functionDeclarations Function declarations.
     * @return list<array<string, mixed>> Tool declarations.
     */
    private function prepareToolsParam(array $functionDeclarations): array
    {
        $tools = [];
        foreach ($functionDeclarations as $functionDeclaration) {
            $tools[] = [
                'type' => 'function',
                'name' => $functionDeclaration->getName(),
                'description' => $functionDeclaration->getDescription(),
                'parameters' => $functionDeclaration->getParameters(),
            ];
        }

        return $tools;
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
        $body = (string) $response->getBody();
        if (strpos($body, 'data: ') !== false) {
            $data = $this->parseSseResponse($body);
        } else {
            $data = $response->getData();
        }

        if (!is_array($data)) {
            $data = [];
        }

        $candidates = [];
        if (isset($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $index => $outputItem) {
                if (!is_array($outputItem)) {
                    continue;
                }
                $candidate = $this->parseOutputItemToCandidate($outputItem, (int) $index);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        if (
            empty($candidates) &&
            isset($data['output_text']) &&
            is_string($data['output_text']) &&
            $data['output_text'] !== ''
        ) {
            $candidates[] = new Candidate(
                new ModelMessage([new MessagePart($data['output_text'])]),
                FinishReasonEnum::stop()
            );
        }

        if (empty($candidates)) {
            throw ResponseException::fromMissingData('ChatGPT Codex', 'output');
        }

        $usage = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
        $inputTokens = $this->getIntegerValue($usage['input_tokens'] ?? null);
        $outputTokens = $this->getIntegerValue($usage['output_tokens'] ?? null);

        return new GenerativeAiResult(
            isset($data['id']) && is_string($data['id']) ? $data['id'] : '',
            $candidates,
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
     * Parses one Codex Responses output item into a candidate.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $outputItem Output item.
     * @param int                  $index      Output index.
     * @return Candidate|null Candidate, or null for unsupported output items.
     */
    private function parseOutputItemToCandidate(array $outputItem, int $index): ?Candidate
    {
        $type = $outputItem['type'] ?? '';
        if ($type === 'function_call') {
            return $this->parseFunctionCallOutputToCandidate($outputItem, $index);
        }

        if ($type !== 'message') {
            return null;
        }

        $parts = [];
        if (isset($outputItem['content']) && is_array($outputItem['content'])) {
            foreach ($outputItem['content'] as $content) {
                if (!is_array($content)) {
                    continue;
                }
                if (
                    in_array(($content['type'] ?? ''), ['output_text', 'text'], true) &&
                    isset($content['text']) &&
                    is_string($content['text']) &&
                    $content['text'] !== ''
                ) {
                    $parts[] = new MessagePart($content['text']);
                }
            }
        }

        if (empty($parts)) {
            return null;
        }

        return new Candidate(new ModelMessage($parts), FinishReasonEnum::stop());
    }

    /**
     * Parses one Codex function_call output item into a tool-call candidate.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $outputItem Function call output item.
     * @param int                  $index      Output index.
     * @return Candidate Tool-call candidate.
     */
    private function parseFunctionCallOutputToCandidate(array $outputItem, int $index): Candidate
    {
        if (!isset($outputItem['call_id']) || !is_string($outputItem['call_id'])) {
            throw ResponseException::fromMissingData('ChatGPT Codex', "output[{$index}].call_id");
        }
        if (!isset($outputItem['name']) || !is_string($outputItem['name'])) {
            throw ResponseException::fromMissingData('ChatGPT Codex', "output[{$index}].name");
        }

        $args = null;
        if (isset($outputItem['arguments']) && is_string($outputItem['arguments'])) {
            $decoded = json_decode($outputItem['arguments'], true);
            if (is_array($decoded) && count($decoded) > 0) {
                $args = $decoded;
            }
        }

        $part = new MessagePart(
            new FunctionCall(
                $outputItem['call_id'],
                $outputItem['name'],
                $args
            )
        );

        return new Candidate(new ModelMessage([$part]), FinishReasonEnum::toolCalls());
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
        $outputItems = [];
        $eventData = '';
        $lines = preg_split("/\r\n|\n|\r/", $body);

        if ($lines === false) {
            $lines = [];
        }

        foreach ($lines as $line) {
            if (trim($line) === '') {
                $this->consumeSseEventData($eventData, $text, $completed, $outputItems);
                $eventData = '';
                continue;
            }

            if (substr($line, 0, 6) === 'data: ') {
                $eventData .= substr($line, 6);
            }
        }

        $this->consumeSseEventData($eventData, $text, $completed, $outputItems);

        if ($text !== '') {
            $completed['output_text'] = $text;
        }
        if (!empty($outputItems) && empty($completed['output'])) {
            $completed['output'] = array_values($outputItems);
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
     * @param array<int, array<string, mixed>> $outputItems Accumulated completed output items.
     * @return void
     */
    private function consumeSseEventData(string $eventData, string &$text, array &$completed, array &$outputItems): void
    {
        if ($eventData === '' || $eventData === '[DONE]') {
            return;
        }

        $data = json_decode($eventData, true);
        if (!is_array($data)) {
            return;
        }

        $type = (string) ($data['type'] ?? '');
        if (
            $type === 'response.output_text.delta' &&
            isset($data['delta']) &&
            is_string($data['delta'])
        ) {
            $text .= $data['delta'];
        } elseif ($type === '' && isset($data['delta']) && is_string($data['delta'])) {
            $text .= $data['delta'];
        }

        if (isset($data['response']) && is_array($data['response']) && $type === 'response.completed') {
            /** @var array<string, mixed> $response */
            $response = $data['response'];
            $completed = $response;
        }

        if (in_array($type, ['response.output_item.done', 'response.output_item.added'], true)) {
            $item = $data['item'] ?? $data['output_item'] ?? null;
            if (is_array($item)) {
                $index = $this->getIntegerValue($data['output_index'] ?? count($outputItems), count($outputItems));
                $outputItems[$index] = $item;
            }
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
