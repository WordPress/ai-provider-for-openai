<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Provider;

/**
 * Operation identifiers passed to OpenAI API profiles.
 *
 * This uses class constants instead of a native enum to retain PHP 7.4 support.
 *
 * @since 1.3.0
 */
final class OpenAiApiOperation
{
    public const LIST_MODELS = 'list_models';
    public const GENERATE_TEXT = 'generate_text';
    public const GENERATE_IMAGE = 'generate_image';
    public const EDIT_IMAGE = 'edit_image';
    public const GENERATE_EMBEDDING = 'generate_embedding';
    public const CONVERT_TEXT_TO_SPEECH = 'convert_text_to_speech';

    /**
     * This class only contains constants and cannot be instantiated.
     */
    private function __construct()
    {
    }
}
