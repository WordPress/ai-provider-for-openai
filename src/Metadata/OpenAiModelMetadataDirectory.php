<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Metadata;

use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

/**
 * Class for the OpenAI model metadata directory.
 *
 * @since 1.0.0
 *
 * @phpstan-type ModelsResponseData array{
 *     data: list<array{id: string}>
 * }
 */
class OpenAiModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * Regular expression matching the model ID prefixes of OpenAI reasoning models.
     *
     * Reasoning models (codex-mini-latest, the versioned GPT-5 family, and the verified o1, o3,
     * and o4 families) must be classified separately from standard GPT models. Only o-families
     * whose behavior has been verified are recognized; new o-families require documentation and
     * tests before they are added. GPT-5 chat aliases share a reasoning-family prefix but are
     * non-reasoning models and are handled separately by self::isNonReasoningChatModel().
     *
     * @since 1.1.0
     *
     * @var string
     */
    private const REASONING_MODEL_ID_PATTERN = '/^(?:codex-mini-latest|gpt-5(?:\.\d+)?|o(?:1|3|4))(?:-|$)/';

    /**
     * Regular expression matching the IDs of reasoning models that use reasoning effort `none` by default.
     *
     * GPT-5.1, GPT-5.2, GPT-5.4, GPT-5.4 mini, and GPT-5.4 nano use reasoning effort `none` by
     * default. Their dated snapshots do as well. Pro and Codex variants are excluded until the
     * documentation confirms that they support this default.
     *
     * @since 1.1.0
     *
     * @var string
     */
    private const EFFORT_NONE_DEFAULT_MODEL_ID_PATTERN =
        '/^gpt-(?:5\.[12]|5\.4(?:-(?:mini|nano))?)(?:-\d{4}-\d{2}-\d{2})?$/';

    /**
     * Regular expression matching GPT-5 chat aliases that may advertise sampling options.
     *
     * Live API verification directly established that the versioned GPT-5.1 and GPT-5.2 chat
     * aliases reject `temperature`, so they are excluded.
     *
     * @since 1.1.0
     *
     * @var string
     */
    private const NON_REASONING_CHAT_MODEL_ID_PATTERN = '/^gpt-5-chat-latest$/';

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        return new Request(
            $method,
            OpenAiProvider::url($path),
            $headers,
            $data
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        /** @var ModelsResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['data']) || !$responseData['data']) {
            throw ResponseException::fromMissingData('OpenAI', 'data');
        }

        $allModalityCombinationsWithText = [
            [ModalityEnum::text()],
            [ModalityEnum::text(), ModalityEnum::image()],
            [ModalityEnum::text(), ModalityEnum::audio()],
            [ModalityEnum::text(), ModalityEnum::document()],
            [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::audio()],
            [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::document()],
            [ModalityEnum::text(), ModalityEnum::audio(), ModalityEnum::document()],
            [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::audio(), ModalityEnum::document()],
        ];

        // Unfortunately, the OpenAI API does not return model capabilities, so we have to hardcode them here.
        $gptCapabilities = [
            CapabilityEnum::textGeneration(),
            CapabilityEnum::chatHistory(),
        ];
        $gptBaseOptions = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::presencePenalty()),
            new SupportedOption(OptionEnum::frequencyPenalty()),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::functionDeclarations()),
            new SupportedOption(OptionEnum::webSearch()),
            new SupportedOption(OptionEnum::customOptions()),
        ];
        // Only models for which self::supportsSamplingOptions() returns true accept these options.
        $gptSamplingOptions = [
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::logprobs()),
            new SupportedOption(OptionEnum::topLogprobs()),
        ];
        $gptOptions = array_merge($gptBaseOptions, $gptSamplingOptions, [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ]);
        $gptReasoningOptions = array_merge($gptBaseOptions, [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ]);
        $gptMultimodalInputOptions = array_merge($gptBaseOptions, $gptSamplingOptions, [
            new SupportedOption(
                OptionEnum::inputModalities(),
                $allModalityCombinationsWithText
            ),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ]);
        $gptReasoningMultimodalInputOptions = array_merge($gptBaseOptions, [
            new SupportedOption(
                OptionEnum::inputModalities(),
                $allModalityCombinationsWithText
            ),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ]);
        $gptMultimodalSpeechOutputOptions = array_merge($gptBaseOptions, $gptSamplingOptions, [
            new SupportedOption(
                OptionEnum::inputModalities(),
                [
                    [ModalityEnum::text()],
                    [ModalityEnum::audio()],
                    [ModalityEnum::text(), ModalityEnum::audio()],
                ]
            ),
            new SupportedOption(
                OptionEnum::outputModalities(),
                [
                    [ModalityEnum::text()],
                    [ModalityEnum::text(), ModalityEnum::audio()],
                ]
            ),
        ]);
        $gptSearchOptions = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::customOptions()),
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ];
        $imageCapabilities = [
            CapabilityEnum::imageGeneration(),
        ];
        // Embedding generation support was added in 1.4.0.
        $supportsEmbeddingGeneration = interface_exists(EmbeddingGenerationModelInterface::class);
        $embeddingCapabilities = [];
        $embeddingOptions = [];
        if ($supportsEmbeddingGeneration) {
            $embeddingCapabilities = [
                CapabilityEnum::embeddingGeneration(),
            ];
            $embeddingOptions = [
                new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
                new SupportedOption(OptionEnum::dimensions()),
                new SupportedOption(OptionEnum::customOptions()),
            ];
        }
        $dalle2Options = [
            new SupportedOption(OptionEnum::inputModalities(), [
                [ModalityEnum::text()],
                [ModalityEnum::text(), ModalityEnum::image()],
            ]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::outputMimeType(), ['image/png']),
            new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline(), FileTypeEnum::remote()]),
            new SupportedOption(OptionEnum::outputMediaOrientation(), [MediaOrientationEnum::square()]),
            new SupportedOption(OptionEnum::outputMediaAspectRatio(), ['1:1']),
            new SupportedOption(OptionEnum::customOptions()),
        ];
        $dalle3Options = [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::outputMimeType(), ['image/png']),
            new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline(), FileTypeEnum::remote()]),
            new SupportedOption(OptionEnum::outputMediaOrientation(), [
                MediaOrientationEnum::square(),
                MediaOrientationEnum::landscape(),
                MediaOrientationEnum::portrait(),
            ]),
            new SupportedOption(OptionEnum::outputMediaAspectRatio(), ['1:1', '7:4', '4:7']),
            new SupportedOption(OptionEnum::customOptions()),
        ];
        $gptImageOptions = [
            new SupportedOption(OptionEnum::inputModalities(), [
                [ModalityEnum::text()],
                [ModalityEnum::text(), ModalityEnum::image()],
            ]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::outputMimeType(), ['image/png', 'image/jpeg', 'image/webp']),
            new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline()]),
            new SupportedOption(OptionEnum::outputMediaOrientation(), [
                MediaOrientationEnum::square(),
                MediaOrientationEnum::landscape(),
                MediaOrientationEnum::portrait(),
            ]),
            new SupportedOption(OptionEnum::outputMediaAspectRatio(), ['1:1', '3:2', '2:3']),
            new SupportedOption(OptionEnum::customOptions()),
        ];
        $ttsCapabilities = [
            CapabilityEnum::textToSpeechConversion(),
        ];
        $ttsOptions = [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::audio()]]),
            new SupportedOption(OptionEnum::outputMimeType(), [
                'audio/mpeg',
                'audio/ogg',
                'audio/wav',
                'audio/flac',
                'audio/aac',
            ]),
            new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline()]),
            new SupportedOption(OptionEnum::outputSpeechVoice()),
            new SupportedOption(OptionEnum::customOptions()),
        ];

        $modelsData = (array) $responseData['data'];

        $models = array_values(
            array_map(
                static function (array $modelData) use (
                    $gptCapabilities,
                    $gptOptions,
                    $gptReasoningOptions,
                    $gptMultimodalInputOptions,
                    $gptReasoningMultimodalInputOptions,
                    $gptMultimodalSpeechOutputOptions,
                    $gptSearchOptions,
                    $imageCapabilities,
                    $embeddingCapabilities,
                    $embeddingOptions,
                    $gptImageOptions,
                    $dalle2Options,
                    $dalle3Options,
                    $ttsCapabilities,
                    $ttsOptions
                ): ModelMetadata {
                    $modelId = $modelData['id'];

                    // Fine-tuned models use the format "ft:{base_model}:{org}:{name}:{id}".
                    // Extract the base model ID for capability detection, but keep the
                    // original model ID for the metadata so API requests use the fine-tuned model.
                    $originalModelId = $modelId;
                    if (str_starts_with($modelId, 'ft:')) {
                        $parts = explode(':', $modelId, 3);
                        if (isset($parts[1])) {
                            $modelId = $parts[1];
                        }
                    }

                    if (str_starts_with($modelId, 'text-embedding-')) {
                        $modelCaps = $embeddingCapabilities;
                        $modelOptions = $embeddingOptions;
                    } elseif (
                        str_starts_with($modelId, 'gpt-image-') ||
                        str_starts_with($modelId, 'chatgpt-image-')
                    ) {
                        $modelCaps = $imageCapabilities;
                        $modelOptions = $gptImageOptions;
                    } elseif ($modelId === 'dall-e-2') {
                        $modelCaps = $imageCapabilities;
                        $modelOptions = $dalle2Options;
                    } elseif (str_starts_with($modelId, 'dall-e-')) {
                        $modelCaps = $imageCapabilities;
                        $modelOptions = $dalle3Options;
                    } elseif (
                        str_starts_with($modelId, 'tts-') ||
                        str_contains($modelId, '-tts')
                    ) {
                        $modelCaps = $ttsCapabilities;
                        $modelOptions = $ttsOptions;
                    } elseif (
                        (
                            str_starts_with($modelId, 'gpt-')
                            || self::isReasoningModel($modelId)
                        )
                        && !str_contains($modelId, '-instruct')
                        && !str_contains($modelId, '-realtime')
                        && !str_contains($modelId, '-transcribe')
                    ) {
                        if (self::supportsMultimodalTextInput($modelId)) {
                            $modelCaps = $gptCapabilities;
                            $modelOptions = $gptMultimodalInputOptions;
                            // New multimodal output model for audio generation.
                            if (str_contains($modelId, '-audio')) {
                                $modelOptions = $gptMultimodalSpeechOutputOptions;
                            } elseif (str_contains($modelId, '-search')) {
                                $modelOptions = $gptSearchOptions;
                            } elseif (!self::supportsSamplingOptions($modelId)) {
                                $modelOptions = $gptReasoningMultimodalInputOptions;
                            }
                        } elseif (!str_contains($modelId, '-audio')) {
                            $modelCaps = $gptCapabilities;
                            $modelOptions = self::supportsSamplingOptions($modelId)
                                ? $gptOptions
                                : $gptReasoningOptions;
                        } else {
                            $modelCaps = [];
                            $modelOptions = [];
                        }
                    } else {
                        $modelCaps = [];
                        $modelOptions = [];
                    }

                    return new ModelMetadata(
                        $originalModelId,
                        $originalModelId, // The OpenAI API does not return a display name.
                        $modelCaps,
                        $modelOptions
                    );
                },
                $modelsData
            )
        );

        usort($models, [$this, 'modelSortCallback']);

        return $models;
    }

    /**
     * Checks whether an OpenAI text generation model supports multimodal input.
     *
     * @since 1.0.3
     *
     * @param string $modelId The model ID.
     * @return bool True if the model supports multimodal text input, false otherwise.
     */
    private static function supportsMultimodalTextInput(string $modelId): bool
    {
        return (bool) preg_match(
            '/^(?:codex-mini-latest|gpt-4-turbo|gpt-4o|gpt-4\.1|gpt-5(?:\.\d+)?|o(?:1|3|4))(?:-|$)/',
            $modelId
        );
    }

    /**
     * Checks whether an OpenAI text generation model is a reasoning model.
     *
     * Reasoning model families include codex-mini-latest, versioned GPT-5 models (e.g. `gpt-5`,
     * `gpt-5.5`), and the verified o1, o3, and o4 families. GPT-5 chat aliases are non-reasoning
     * models and are handled separately; see {@see self::isNonReasoningChatModel()} and
     * {@see self::supportsSamplingOptions()}.
     *
     * @since 1.1.0
     *
     * @param string $modelId The model ID.
     * @return bool True if the model is a reasoning model, false otherwise.
     */
    private static function isReasoningModel(string $modelId): bool
    {
        return (bool) preg_match(self::REASONING_MODEL_ID_PATTERN, $modelId);
    }

    /**
     * Checks whether an OpenAI reasoning model uses reasoning effort `none` by default.
     *
     * @since 1.1.0
     *
     * @param string $modelId The model ID.
     * @return bool True if the model's default reasoning effort is `none`, false otherwise.
     */
    private static function hasDefaultReasoningEffortNone(string $modelId): bool
    {
        return (bool) preg_match(self::EFFORT_NONE_DEFAULT_MODEL_ID_PATTERN, $modelId);
    }

    /**
     * Checks whether an OpenAI GPT-5 chat alias is a non-reasoning model.
     *
     * @since 1.1.0
     *
     * @param string $modelId The model ID.
     * @return bool True if the model is a non-reasoning chat alias, false otherwise.
     */
    private static function isNonReasoningChatModel(string $modelId): bool
    {
        return (bool) preg_match(self::NON_REASONING_CHAT_MODEL_ID_PATTERN, $modelId);
    }

    /**
     * Checks whether an OpenAI text generation model supports the sampling options.
     *
     * The sampling options are `temperature`, `top_p`, `logprobs`, and `top_logprobs`. They are
     * supported by non-reasoning models, GPT-5 chat aliases that are explicitly non-reasoning, and
     * reasoning models whose default reasoning effort is `none`.
     *
     * @since 1.1.0
     *
     * @param string $modelId The model ID.
     * @return bool True if the model supports the sampling options, false otherwise.
     */
    private static function supportsSamplingOptions(string $modelId): bool
    {
        return !self::isReasoningModel($modelId)
            || self::isNonReasoningChatModel($modelId)
            || self::hasDefaultReasoningEffortNone($modelId);
    }

    /**
     * Callback function for sorting models by ID, to be used with `usort()`.
     *
     * This method expresses preferences for certain models or model families within the provider by putting them
     * earlier in the sorted list. The objective is not to be opinionated about which models are better, but to ensure
     * that more commonly used, more recent, or flagship models are presented first to users.
     *
     * @since 1.0.0
     *
     * @param ModelMetadata $a First model.
     * @param ModelMetadata $b Second model.
     * @return int Comparison result.
     */
    protected function modelSortCallback(ModelMetadata $a, ModelMetadata $b): int
    {
        $aId = $a->getId();
        $bId = $b->getId();

        // Prefer non-preview models over preview models.
        if (str_contains($aId, '-preview') && !str_contains($bId, '-preview')) {
            return 1;
        }
        if (str_contains($bId, '-preview') && !str_contains($aId, '-preview')) {
            return -1;
        }

        // Prefer GPT models over non-GPT models.
        if (str_starts_with($aId, 'gpt-') && !str_starts_with($bId, 'gpt-')) {
            return -1;
        }
        if (str_starts_with($bId, 'gpt-') && !str_starts_with($aId, 'gpt-')) {
            return 1;
        }

        // Prefer GPT models with version numbers (e.g. 'gpt-5.1', 'gpt-5') over those without.
        $aMatch = preg_match('/^gpt-([0-9.]+)(-[a-z0-9-]+)?$/', $aId, $aMatches);
        $bMatch = preg_match('/^gpt-([0-9.]+)(-[a-z0-9-]+)?$/', $bId, $bMatches);
        if ($aMatch && !$bMatch) {
            return -1;
        }
        if ($bMatch && !$aMatch) {
            return 1;
        }
        if ($aMatch && $bMatch) {
            // Prefer later model versions.
            $aVersion = $aMatches[1];
            $bVersion = $bMatches[1];
            if (version_compare($aVersion, $bVersion, '>')) {
                return -1;
            }
            if (version_compare($bVersion, $aVersion, '>')) {
                return 1;
            }

            // Prefer models without a suffix (i.e. base models) over those with a suffix.
            if (!isset($aMatches[2]) && isset($bMatches[2])) {
                return -1;
            }
            if (!isset($bMatches[2]) && isset($aMatches[2])) {
                return 1;
            }

            // Prefer '-mini' models over others with a suffix.
            if (isset($aMatches[2]) && isset($bMatches[2])) {
                if ($aMatches[2] === '-mini' && $bMatches[2] !== '-mini') {
                    return -1;
                }
                if ($bMatches[2] === '-mini' && $aMatches[2] !== '-mini') {
                    return 1;
                }
            }
        }

        // Fallback: Sort alphabetically.
        return strcmp($a->getId(), $b->getId());
    }
}
