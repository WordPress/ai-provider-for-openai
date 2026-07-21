<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

/**
 * Class for an OpenAI text-to-speech conversion model using the Audio Speech API.
 *
 * Uses the `/audio/speech` endpoint to synthesize speech from text with models
 * such as `tts-1`, `tts-1-hd`, and `gpt-4o-mini-tts`.
 *
 * @since n.e.x.t
 */
class OpenAiTextToSpeechConversionModel extends AbstractApiBasedModel implements
    TextToSpeechConversionModelInterface
{
    /**
     * Default voice used when none is configured.
     */
    private const DEFAULT_VOICE = 'alloy';

    /**
     * Default output MIME type used when none is configured.
     */
    private const DEFAULT_MIME_TYPE = 'audio/mpeg';

    /**
     * Maps supported output MIME types to OpenAI `response_format` values.
     *
     * @var array<string, string>
     */
    private const MIME_TYPE_TO_RESPONSE_FORMAT = [
        'audio/mpeg' => 'mp3',
        'audio/ogg'  => 'opus',
        'audio/wav'  => 'wav',
        'audio/flac' => 'flac',
        'audio/aac'  => 'aac',
    ];

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function convertTextToSpeechResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();

        $mimeType = $this->prepareOutputMimeType();
        $params   = $this->prepareConvertParams($prompt, $mimeType);

        $request = new Request(
            HttpMethodEnum::POST(),
            OpenAiProvider::url('audio/speech'),
            ['Content-Type' => 'application/json'],
            $params,
            $this->getRequestOptions()
        );

        // Add authentication credentials to the request.
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        // Send and process the request.
        $response = $httpTransporter->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseResponseToGenerativeAiResult($response, $mimeType);
    }

    /**
     * Prepares the request parameters for the Audio Speech API.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $prompt   The prompt messages containing the text.
     * @param string        $mimeType The resolved output MIME type.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareConvertParams(array $prompt, string $mimeType): array
    {
        $config = $this->getConfig();

        $params = [
            'model'           => $this->metadata()->getId(),
            'input'           => $this->preparePromptText($prompt),
            'voice'           => $this->prepareVoice(),
            'response_format' => self::MIME_TYPE_TO_RESPONSE_FORMAT[$mimeType],
        ];

        /*
         * Any custom options are added to the parameters as well.
         * This allows developers to pass other options such as 'speed' or
         * 'instructions' that may not yet be first-class options in the SDK.
         */
        $customOptions = $config->getCustomOptions();
        foreach ($customOptions as $key => $value) {
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The custom option "%s" conflicts with an existing parameter.',
                        $key
                    )
                );
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Extracts the plain text to synthesize from the prompt.
     *
     * The Audio Speech API accepts a single block of text, so this concatenates
     * the text parts found across the prompt messages.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $prompt The prompt messages.
     * @return string The text to convert to speech.
     * @throws InvalidArgumentException If no text is found in the prompt.
     */
    protected function preparePromptText(array $prompt): string
    {
        $text = '';
        foreach ($prompt as $message) {
            foreach ($message->getParts() as $part) {
                $partText = $part->getText();
                if ($partText !== null) {
                    $text .= ('' === $text ? '' : "\n") . $partText;
                }
            }
        }

        if ('' === $text) {
            throw new InvalidArgumentException(
                'The prompt must contain text to convert to speech.'
            );
        }

        return $text;
    }

    /**
     * Resolves the voice to use, falling back to the default.
     *
     * @since n.e.x.t
     *
     * @return string The voice identifier.
     */
    protected function prepareVoice(): string
    {
        $voice = $this->getConfig()->getOutputSpeechVoice();

        return ($voice === null || '' === $voice) ? self::DEFAULT_VOICE : $voice;
    }

    /**
     * Resolves the output MIME type, defaulting and validating against supported types.
     *
     * @since n.e.x.t
     *
     * @return string A supported audio MIME type.
     * @throws InvalidArgumentException If the configured MIME type is unsupported.
     */
    protected function prepareOutputMimeType(): string
    {
        $mimeType = $this->getConfig()->getOutputMimeType();
        if ($mimeType === null || '' === $mimeType) {
            return self::DEFAULT_MIME_TYPE;
        }

        if (!isset(self::MIME_TYPE_TO_RESPONSE_FORMAT[$mimeType])) {
            throw new InvalidArgumentException(
                sprintf(
                    'The output MIME type "%s" is not supported for text to speech.',
                    $mimeType
                )
            );
        }

        return $mimeType;
    }

    /**
     * Parses the binary audio response into a generative AI result.
     *
     * The Audio Speech API returns raw audio bytes rather than JSON, so the body
     * is base64-encoded into an inline audio file.
     *
     * @since n.e.x.t
     *
     * @param Response $response The HTTP response containing raw audio bytes.
     * @param string   $mimeType The MIME type of the returned audio.
     * @return GenerativeAiResult The generative AI result containing the audio file.
     * @throws InvalidArgumentException If the response body is empty.
     */
    protected function parseResponseToGenerativeAiResult(Response $response, string $mimeType): GenerativeAiResult
    {
        $body = $response->getBody();
        if ($body === null || '' === $body) {
            throw new InvalidArgumentException(
                'The API returned an empty audio response.'
            );
        }

        $audioFile = new File(base64_encode($body), $mimeType);

        $message   = new Message(MessageRoleEnum::model(), [new MessagePart($audioFile)]);
        $candidate = new Candidate($message, FinishReasonEnum::stop());

        return new GenerativeAiResult(
            $this->generateResultId(),
            [$candidate],
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata()
        );
    }

    /**
     * Generates a result identifier.
     *
     * The Audio Speech API does not return an identifier, so one is generated
     * locally for the result.
     *
     * @since n.e.x.t
     *
     * @return string The result identifier.
     */
    protected function generateResultId(): string
    {
        return uniqid('openai-tts-', true);
    }
}
