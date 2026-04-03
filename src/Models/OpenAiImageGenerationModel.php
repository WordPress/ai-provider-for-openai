<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

/**
 * Class for an OpenAI image generation model using the Images API.
 *
 * This uses the Images API directly to generate images with GPT image models
 * (gpt-image-1, chatgpt-image-latest, etc.) and DALL-E models (dall-e-2, dall-e-3).
 *
 * GPT image models and DALL-E 2 also support image editing via the `/images/edits`
 * endpoint. Editing is triggered when the prompt message contains an image file
 * alongside the text instruction (i.e. a `[text, image]` input modality).
 *
 * @since 1.0.0
 */
class OpenAiImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel
{
    /**
     * {@inheritDoc}
     *
     * When the prompt contains an image file (text + image input), the request is
     * routed to the `/images/edits` endpoint instead of `/images/generations`.
     * This supports GPT image models (gpt-image-*, chatgpt-image-*) and DALL-E 2.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $prompt The prompt to generate or edit an image for.
     * @return GenerativeAiResult The generative AI result.
     */
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        if ($this->promptContainsImage($prompt)) {
            return $this->generateImageEditResult($prompt);
        }

        return parent::generateImageResult($prompt);
    }

    /**
     * Checks whether any message in the prompt contains an image file.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $prompt The prompt messages to check.
     * @return bool True if the prompt contains at least one image file.
     */
    protected function promptContainsImage(array $prompt): bool
    {
        foreach ($prompt as $message) {
            foreach ($message->getParts() as $part) {
                $file = $part->getFile();
                if ($file !== null && $file->isImage()) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        return new Request(
            $method,
            OpenAiProvider::url($path),
            $headers,
            $data,
            $this->getRequestOptions()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function prepareGenerateImageParams(array $prompt): array
    {
        $params = parent::prepareGenerateImageParams($prompt);

        /*
         * Only the newer 'gpt-image-' models support passing a MIME type ('output_format').
         * Conversely, they do not support 'response_format', but always return a base64 encoded image.
         */
        if ($this->isGptImageModel($params['model'])) {
            unset($params['response_format']);
        } else {
            unset($params['output_format']);
        }

        return $params;
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function prepareSizeParam(?MediaOrientationEnum $orientation, ?string $aspectRatio): string
    {
        $modelId = $this->metadata()->getId();

        if ($this->isGptImageModel($modelId)) {
            return $this->prepareGptImageSizeParam($orientation, $aspectRatio);
        }

        return $this->prepareDalleSizeParam($modelId, $orientation, $aspectRatio);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function getResultId(array $responseData): string
    {
        // The Images API returns `created` timestamp instead of `id`.
        return isset($responseData['created']) && is_int($responseData['created'])
            ? 'img-' . $responseData['created']
            : '';
    }

    /**
     * Checks if the given model ID is a GPT image model.
     *
     * This includes both `gpt-image-*` and `chatgpt-image-*` model families,
     * which share the same API capabilities and parameter format.
     *
     * @since 1.0.0
     *
     * @param string $modelId The model ID to check.
     * @return bool True if it's a GPT image model, false otherwise.
     */
    protected function isGptImageModel(string $modelId): bool
    {
        return str_starts_with($modelId, 'gpt-image-')
            || str_starts_with($modelId, 'chatgpt-image-');
    }

    /**
     * Prepares the size parameter for GPT image models.
     *
     * @since 1.0.0
     *
     * @param MediaOrientationEnum|null $orientation The desired media orientation.
     * @param string|null $aspectRatio The desired media aspect ratio.
     * @return string The size parameter value.
     */
    protected function prepareGptImageSizeParam(?MediaOrientationEnum $orientation, ?string $aspectRatio): string
    {
        // If aspect ratio is provided, map it to OpenAI size format.
        if ($aspectRatio !== null) {
            $aspectRatioMap = [
                '1:1' => '1024x1024',
                '3:2' => '1536x1024',
                '2:3' => '1024x1536',
            ];
            if (isset($aspectRatioMap[$aspectRatio])) {
                return $aspectRatioMap[$aspectRatio];
            }
        }

        // Map orientation to size.
        if ($orientation !== null) {
            if ($orientation->isLandscape()) {
                return '1536x1024';
            }
            if ($orientation->isPortrait()) {
                return '1024x1536';
            }
        }

        // Default to square.
        return '1024x1024';
    }

    /**
     * Generates an image edit result using the `/images/edits` endpoint.
     *
     * The prompt should contain a single user message with both text (the edit instruction)
     * and an image file (the source image to edit).
     *
     * @since n.e.x.t
     *
     * @param list<Message> $prompt The prompt containing the source image and edit instructions.
     * @return GenerativeAiResult The generative AI result containing the edited image.
     * @throws InvalidArgumentException If the prompt does not contain a valid image or text instruction.
     */
    protected function generateImageEditResult(array $prompt): GenerativeAiResult
    {
        $editData = $this->extractEditData($prompt);
        $params = $this->prepareEditParams($editData['text']);

        $boundary = bin2hex(random_bytes(16));
        $body = $this->buildMultipartBody($params, $editData['image'], $boundary);

        $request = $this->createRequest(
            HttpMethodEnum::POST(),
            'images/edits',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $body
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        $response = $this->getHttpTransporter()->send($request);
        $this->throwIfNotSuccessful($response);

        // Determine output MIME type based on model family.
        if ($this->isGptImageModel($this->metadata()->getId())) {
            $outputFormat = isset($params['output_format']) && is_string($params['output_format'])
                ? $params['output_format']
                : 'png';
            $outputMimeType = 'image/' . $outputFormat;
        } else {
            // DALL-E 2 always returns PNG.
            $outputMimeType = 'image/png';
        }

        return $this->parseResponseToGenerativeAiResult($response, $outputMimeType);
    }

    /**
     * Extracts the source image file and edit text instruction from the prompt.
     *
     * Iterates all message parts to find the first image file and the first text part.
     * Both are expected within a single user message, but this method is lenient about
     * the message structure to allow flexibility.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $prompt The prompt to extract data from.
     * @return array{image: File, text: string} The extracted image file and text instruction.
     * @throws InvalidArgumentException If no image or no user text instruction is found.
     */
    protected function extractEditData(array $prompt): array
    {
        $imageFile = null;
        $textPrompt = null;

        foreach ($prompt as $message) {
            foreach ($message->getParts() as $part) {
                if ($imageFile === null) {
                    $file = $part->getFile();
                    if ($file !== null && $file->isImage()) {
                        $imageFile = $file;
                    }
                }

                if ($textPrompt === null && $message->getRole()->isUser()) {
                    $text = $part->getText();
                    if ($text !== null) {
                        $textPrompt = $text;
                    }
                }
            }
        }

        if ($imageFile === null) {
            throw new InvalidArgumentException(
                'The prompt must contain an image file to edit.'
            );
        }

        if ($textPrompt === null) {
            throw new InvalidArgumentException(
                'The prompt must contain a user message with text instructions for the edit.'
            );
        }

        return ['image' => $imageFile, 'text' => $textPrompt];
    }

    /**
     * Prepares the parameters for an image edit request.
     *
     * Parameter handling differs by model family:
     * - GPT image models use `output_format` and `prepareGptImageSizeParam()` sizes.
     * - DALL-E 2 uses `response_format` (url/b64_json) and `prepareDalleSizeParam()` sizes.
     *
     * @since n.e.x.t
     *
     * @param string $textPrompt The text instruction for the edit.
     * @return array<string, mixed> The parameters for the API request.
     * @throws InvalidArgumentException If a custom option conflicts with an existing parameter.
     */
    protected function prepareEditParams(string $textPrompt): array
    {
        $config = $this->getConfig();
        $modelId = $this->metadata()->getId();
        $isGptImage = $this->isGptImageModel($modelId);

        $params = [
            'model' => $modelId,
            'prompt' => $textPrompt,
        ];

        $candidateCount = $config->getCandidateCount();
        if ($candidateCount !== null) {
            $params['n'] = $candidateCount;
        }

        // Size handling differs by model family.
        $outputMediaOrientation = $config->getOutputMediaOrientation();
        $outputMediaAspectRatio = $config->getOutputMediaAspectRatio();
        if ($outputMediaOrientation !== null || $outputMediaAspectRatio !== null) {
            if ($isGptImage) {
                $params['size'] = $this->prepareGptImageSizeParam($outputMediaOrientation, $outputMediaAspectRatio);
            } else {
                $params['size'] = $this->prepareDalleSizeParam(
                    $modelId,
                    $outputMediaOrientation,
                    $outputMediaAspectRatio
                );
            }
        }

        // Output format handling differs by model family.
        if ($isGptImage) {
            // GPT image models use output_format (png, jpeg, webp).
            $outputMimeType = $config->getOutputMimeType();
            if ($outputMimeType !== null) {
                $params['output_format'] = (string) preg_replace('/^image\//', '', $outputMimeType);
            }
        } else {
            // DALL-E 2 uses response_format (url or b64_json).
            $outputFileType = $config->getOutputFileType();
            if ($outputFileType !== null && $outputFileType->isRemote()) {
                $params['response_format'] = 'url';
            } else {
                $params['response_format'] = 'b64_json';
            }
        }

        // Custom options (e.g., quality, background, mask for GPT models).
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
     * Builds a multipart/form-data body from the given parameters and image file.
     *
     * Only inline (base64) images are supported. The `/images/edits` endpoint requires
     * the image to be uploaded as binary file data; remote URLs cannot be sent directly.
     * In practice this is not a limitation for the edit flow, since gpt-image-* models
     * always return inline images.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $params The scalar form fields to include.
     * @param File $imageFile The source image file to include. Must be an inline (base64) image.
     * @param string $boundary The multipart boundary string.
     * @return string The raw multipart/form-data body.
     * @throws InvalidArgumentException If the image is remote, has no base64 data, or cannot be decoded.
     */
    protected function buildMultipartBody(array $params, File $imageFile, string $boundary): string
    {
        if ($imageFile->isRemote()) {
            throw new InvalidArgumentException(
                'Remote image URLs are not supported for image editing. Please provide an inline (base64) image.'
            );
        }

        $base64Data = $imageFile->getBase64Data();
        if ($base64Data === null) {
            throw new InvalidArgumentException(
                'The image file has no base64 data.'
            );
        }

        $binaryData = base64_decode($base64Data, true);
        if ($binaryData === false) {
            throw new InvalidArgumentException(
                'Failed to decode the base64 image data.'
            );
        }

        $ext = (string) str_replace(
            ['image/jpeg', 'image/'],
            ['jpg', ''],
            $imageFile->getMimeType()
        );

        $body = '';

        foreach ($params as $key => $value) {
            if (!is_scalar($value)) {
                throw new InvalidArgumentException(
                    sprintf('The parameter "%s" must be a scalar value for multipart requests.', $key)
                );
            }
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $key . '"' . "\r\n\r\n";
            $body .= (string) $value . "\r\n";
        }

        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="image"; filename="image.' . $ext . '"' . "\r\n";
        $body .= 'Content-Type: ' . $imageFile->getMimeType() . "\r\n\r\n";
        $body .= $binaryData . "\r\n";
        $body .= '--' . $boundary . '--' . "\r\n";

        return $body;
    }

    /**
     * Prepares the size parameter for DALL-E models.
     *
     * @since 1.0.0
     *
     * @param string $modelId The model ID (dall-e-2 or dall-e-3).
     * @param MediaOrientationEnum|null $orientation The desired media orientation.
     * @param string|null $aspectRatio The desired media aspect ratio.
     * @return string The size parameter value.
     */
    protected function prepareDalleSizeParam(
        string $modelId,
        ?MediaOrientationEnum $orientation,
        ?string $aspectRatio
    ): string {
        $isDalle3 = $modelId === 'dall-e-3';

        // If aspect ratio is provided, map it to size.
        if ($aspectRatio !== null) {
            if ($isDalle3) {
                $aspectRatioMap = [
                    '1:1' => '1024x1024',
                    '7:4' => '1792x1024',
                    '4:7' => '1024x1792',
                ];
            } else {
                // DALL-E 2 only supports square images at various resolutions.
                $aspectRatioMap = [
                    '1:1' => '1024x1024',
                ];
            }
            if (isset($aspectRatioMap[$aspectRatio])) {
                return $aspectRatioMap[$aspectRatio];
            }
        }

        // Map orientation to size.
        if ($orientation !== null) {
            if ($isDalle3) {
                if ($orientation->isLandscape()) {
                    return '1792x1024';
                }
                if ($orientation->isPortrait()) {
                    return '1024x1792';
                }
            }
            // DALL-E 2 only supports square, so orientation doesn't change the size.
        }

        // Default to square.
        return '1024x1024';
    }
}
