<?php

declare(strict_types=1);

// Standalone contract tests necessarily declare symbols and execute a runner.
// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

use WordPress\AiClient\Common\Exception\RuntimeException as AiRuntimeException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\OpenAiAiProvider\Contracts\OpenAiApiProfileAwareAuthenticationInterface;
use WordPress\OpenAiAiProvider\Contracts\OpenAiApiProfileInterface;
use WordPress\OpenAiAiProvider\Metadata\OpenAiModelMetadataDirectory;
use WordPress\OpenAiAiProvider\Models\OpenAiImageGenerationModel;
use WordPress\OpenAiAiProvider\Models\OpenAiTextGenerationModel;
use WordPress\OpenAiAiProvider\Provider\OpenAiApiOperation;
use WordPress\OpenAiAiProvider\Provider\OpenAiApiProfileResolver;

/** @var array<string, list<callable>> */
$GLOBALS['openai_profile_test_filters'] = [];

/**
 * Minimal WordPress filter implementation for resolver contract tests.
 *
 * @param mixed $value The value being filtered.
 * @param mixed ...$args Additional filter arguments.
 * @return mixed The filtered value.
 */
function apply_filters(string $hookName, $value, ...$args)
{
    $callbacks = $GLOBALS['openai_profile_test_filters'][$hookName] ?? [];
    foreach ($callbacks as $callback) {
        $value = $callback($value, ...$args);
    }

    return $value;
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/autoload.php';

/**
 * Shared event trace for request-order assertions.
 */
final class OpenAiProfileTestTrace
{
    /** @var list<string> */
    public array $events = [];
}

/**
 * Configurable alternate API profile used by the contract tests.
 */
final class OpenAiProfileTestProfile implements OpenAiApiProfileInterface
{
    private string $baseUrl;
    private string $cacheKey;

    /** @var list<ModelMetadata>|null */
    private ?array $parsedModels = null;

    /** @var list<string> */
    private array $supportedOperations;

    private bool $cacheKeyFailure = false;
    private ?Response $normalizedResponse = null;
    private ?string $normalizedText = null;
    private OpenAiProfileTestTrace $trace;

    /** @var list<array{defaultUrl: string, path: string, operation: string}> */
    public array $urlCalls = [];

    /**
     * @param list<string> $supportedOperations Supported operation identifiers.
     */
    public function __construct(
        string $cacheKey,
        array $supportedOperations,
        OpenAiProfileTestTrace $trace,
        string $baseUrl = 'https://profile.example/v1'
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cacheKey = $cacheKey;
        $this->supportedOperations = $supportedOperations;
        $this->trace = $trace;
    }

    public function getCacheKey(): string
    {
        if ($this->cacheKeyFailure) {
            throw new \RuntimeException('The profile cache identity is unavailable.');
        }

        return $this->cacheKey;
    }

    public function supportsOperation(string $operation): bool
    {
        return in_array($operation, $this->supportedOperations, true);
    }

    public function getRequestUrl(
        string $defaultUrl,
        string $path,
        string $operation
    ): string {
        $this->urlCalls[] = [
            'defaultUrl' => $defaultUrl,
            'path' => $path,
            'operation' => $operation,
        ];
        $this->trace->events[] = 'url:' . $operation;

        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    public function prepareRequest(Request $request, string $operation): Request
    {
        $this->trace->events[] = 'prepare:' . $operation;

        return $request->withHeader('X-Profile-Prepared', $operation);
    }

    public function normalizeResponse(Response $response, string $operation): Response
    {
        $this->trace->events[] = 'normalize:' . $operation;
        if ($this->normalizedResponse !== null) {
            return $this->normalizedResponse;
        }
        if ($this->normalizedText === null) {
            return $response;
        }

        return openai_profile_test_text_response($this->normalizedText);
    }

    public function parseModelMetadataList(Response $response): ?array
    {
        unset($response);
        $this->trace->events[] = 'parse-models';

        return $this->parsedModels;
    }

    /**
     * Supplies metadata returned by parseModelMetadataList().
     *
     * @param list<ModelMetadata> $models Model metadata list.
     */
    public function setParsedModels(array $models): void
    {
        $this->parsedModels = $models;
    }

    /**
     * Makes normalizeResponse() return a standard Responses API payload.
     */
    public function setNormalizedText(string $text): void
    {
        $this->normalizedText = $text;
    }

    /**
     * Makes normalizeResponse() return a specific response.
     */
    public function setNormalizedResponse(Response $response): void
    {
        $this->normalizedResponse = $response;
    }

    /**
     * Makes getCacheKey() fail for fail-closed cache tests.
     */
    public function failCacheKeyLookup(): void
    {
        $this->cacheKeyFailure = true;
    }
}

/**
 * Authentication carrying a profile, with an assertion that preparation ran first.
 */
final class OpenAiProfileTestAuthentication extends ApiKeyRequestAuthentication implements
    OpenAiApiProfileAwareAuthenticationInterface
{
    private OpenAiApiProfileInterface $profile;
    private OpenAiProfileTestTrace $trace;

    public int $profileResolutionCount = 0;

    public function __construct(
        OpenAiApiProfileInterface $profile,
        OpenAiProfileTestTrace $trace,
        string $apiKey = 'profile-test-key'
    ) {
        parent::__construct($apiKey);
        $this->profile = $profile;
        $this->trace = $trace;
    }

    public function getOpenAiApiProfile(): OpenAiApiProfileInterface
    {
        $this->profileResolutionCount++;

        return $this->profile;
    }

    public function authenticateRequest(Request $request): Request
    {
        if (!$request->hasHeader('X-Profile-Prepared')) {
            throw new \RuntimeException('The profile request was not prepared before authentication.');
        }

        $this->trace->events[] = 'authenticate';

        return parent::authenticateRequest($request);
    }
}

/**
 * Capturing HTTP transporter for request and short-circuit assertions.
 */
final class OpenAiProfileTestTransporter implements HttpTransporterInterface
{
    public ?Request $request = null;
    public int $sendCount = 0;

    private Response $response;
    private ?OpenAiProfileTestTrace $trace;

    public function __construct(Response $response, ?OpenAiProfileTestTrace $trace = null)
    {
        $this->response = $response;
        $this->trace = $trace;
    }

    public function send(Request $request, ?RequestOptions $options = null): Response
    {
        unset($options);
        $this->request = $request;
        $this->sendCount++;
        if ($this->trace !== null) {
            $this->trace->events[] = 'transport';
        }

        return $this->response;
    }
}

/**
 * Exposes the protected cache prefix for isolation contract tests.
 */
final class OpenAiProfileTestMetadataDirectory extends OpenAiModelMetadataDirectory
{
    public function baseCacheKey(): string
    {
        return $this->getBaseCacheKey();
    }
}

/**
 * Resets global test state between contract tests.
 */
function openai_profile_test_reset(): void
{
    $GLOBALS['openai_profile_test_filters'] = [];
}

/**
 * @param mixed $actual Actual value.
 * @param mixed $expected Expected value.
 */
function openai_profile_test_same($actual, $expected, string $message = ''): void
{
    if ($actual === $expected) {
        return;
    }

    throw new \RuntimeException(
        ($message !== '' ? $message . ': ' : '')
        . 'expected ' . var_export($expected, true)
        . ', got ' . var_export($actual, true)
    );
}

/**
 * @param mixed $value Value expected to be truthy.
 */
function openai_profile_test_true($value, string $message = ''): void
{
    openai_profile_test_same((bool) $value, true, $message);
}

/**
 * Creates metadata for a test model.
 *
 * @param object $capability Capability enum value.
 */
function openai_profile_test_model_metadata(string $id, $capability): ModelMetadata
{
    return new ModelMetadata($id, $id, [$capability], []);
}

/**
 * Creates provider metadata for a test model.
 */
function openai_profile_test_provider_metadata(): ProviderMetadata
{
    return new ProviderMetadata('openai', 'OpenAI', ProviderTypeEnum::cloud());
}

/**
 * Creates a successful standard Responses API response.
 */
function openai_profile_test_text_response(string $text): Response
{
    $body = json_encode([
        'id' => 'response-contract-test',
        'status' => 'completed',
        'output' => [
            [
                'type' => 'message',
                'role' => 'assistant',
                'status' => 'completed',
                'content' => [
                    [
                        'type' => 'output_text',
                        'text' => $text,
                    ],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 2,
            'output_tokens' => 3,
            'total_tokens' => 5,
        ],
    ]);
    if (!is_string($body)) {
        throw new \RuntimeException('The test response could not be encoded.');
    }

    return new Response(200, ['Content-Type' => 'application/json'], $body);
}

/**
 * Creates a successful standard Images API response.
 */
function openai_profile_test_image_response(string $imageData): Response
{
    $body = json_encode([
        'created' => 1234567890,
        'data' => [
            ['b64_json' => base64_encode($imageData)],
        ],
        'usage' => [
            'input_tokens' => 1,
            'output_tokens' => 2,
            'total_tokens' => 3,
        ],
    ]);
    if (!is_string($body)) {
        throw new \RuntimeException('The test image response could not be encoded.');
    }

    return new Response(200, ['Content-Type' => 'application/json'], $body);
}

/**
 * Creates a simple user prompt.
 *
 * @return list<Message>
 */
function openai_profile_test_prompt(): array
{
    return [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
}

/** @var array<string, callable> $tests */
$tests = [];

$tests['plain API-key behavior remains unchanged'] = static function (): void {
    openai_profile_test_reset();
    $authentication = new ApiKeyRequestAuthentication('standard-api-key');
    openai_profile_test_same(OpenAiApiProfileResolver::resolve($authentication), null);

    $transporter = new OpenAiProfileTestTransporter(
        openai_profile_test_text_response('Standard API response')
    );
    $model = new OpenAiTextGenerationModel(
        openai_profile_test_model_metadata('gpt-standard', CapabilityEnum::textGeneration()),
        openai_profile_test_provider_metadata()
    );
    $model->setRequestAuthentication($authentication);
    $model->setHttpTransporter($transporter);

    $result = $model->generateTextResult(openai_profile_test_prompt());
    openai_profile_test_same($result->toText(), 'Standard API response');
    openai_profile_test_same($transporter->sendCount, 1);
    openai_profile_test_true($transporter->request instanceof Request);
    openai_profile_test_same(
        $transporter->request->getUri(),
        'https://api.openai.com/v1/responses'
    );
    openai_profile_test_same(
        $transporter->request->getHeaderAsString('Authorization'),
        'Bearer standard-api-key'
    );
    openai_profile_test_same($transporter->request->hasHeader('X-Profile-Prepared'), false);

    $requestData = $transporter->request->getData();
    openai_profile_test_true(is_array($requestData));
    openai_profile_test_same($requestData['model'] ?? null, 'gpt-standard');
    openai_profile_test_true(isset($requestData['input']));
};

$tests['profile prepares before auth and normalizes successful response'] = static function (): void {
    openai_profile_test_reset();
    $trace = new OpenAiProfileTestTrace();
    $profile = new OpenAiProfileTestProfile(
        'profile-text',
        [OpenAiApiOperation::GENERATE_TEXT],
        $trace
    );
    $profile->setNormalizedText('Normalized profile response');
    $authentication = new OpenAiProfileTestAuthentication($profile, $trace);
    $rawResponse = new Response(
        200,
        ['Content-Type' => 'application/x-profile-response'],
        '{"alternateText":"raw"}'
    );
    $transporter = new OpenAiProfileTestTransporter($rawResponse, $trace);
    $model = new OpenAiTextGenerationModel(
        openai_profile_test_model_metadata('gpt-profile', CapabilityEnum::textGeneration()),
        openai_profile_test_provider_metadata()
    );
    $model->setRequestAuthentication($authentication);
    $model->setHttpTransporter($transporter);

    $result = $model->generateTextResult(openai_profile_test_prompt());
    openai_profile_test_same($result->toText(), 'Normalized profile response');
    openai_profile_test_same(
        $authentication->profileResolutionCount,
        1,
        'A text model must pin one profile for the bound authentication'
    );
    openai_profile_test_same(
        $trace->events,
        [
            'url:' . OpenAiApiOperation::GENERATE_TEXT,
            'prepare:' . OpenAiApiOperation::GENERATE_TEXT,
            'authenticate',
            'transport',
            'normalize:' . OpenAiApiOperation::GENERATE_TEXT,
        ]
    );
    openai_profile_test_same(
        $transporter->request instanceof Request ? $transporter->request->getUri() : '',
        'https://profile.example/v1/responses'
    );
    openai_profile_test_same(
        $transporter->request instanceof Request
            ? $transporter->request->getHeaderAsString('X-Profile-Prepared')
            : null,
        OpenAiApiOperation::GENERATE_TEXT
    );
    openai_profile_test_same(
        $profile->urlCalls,
        [[
            'defaultUrl' => 'https://api.openai.com/v1/responses',
            'path' => 'responses',
            'operation' => OpenAiApiOperation::GENERATE_TEXT,
        ]]
    );
};

$tests['alternate model catalog parsing and cache isolation'] = static function (): void {
    openai_profile_test_reset();
    $traceA = new OpenAiProfileTestTrace();
    $profileA = new OpenAiProfileTestProfile(
        'tenant-sensitive-value-alpha',
        [OpenAiApiOperation::LIST_MODELS],
        $traceA
    );
    $profileA->setParsedModels([
        openai_profile_test_model_metadata('profile-model', CapabilityEnum::textGeneration()),
    ]);
    $authenticationA = new OpenAiProfileTestAuthentication($profileA, $traceA);
    $directoryA = new OpenAiProfileTestMetadataDirectory();
    $transporterA = new OpenAiProfileTestTransporter(
        new Response(200, [], '{"profileModels":true}'),
        $traceA
    );
    $directoryA->setRequestAuthentication($authenticationA);
    $directoryA->setHttpTransporter($transporterA);

    $models = $directoryA->listModelMetadata();
    openai_profile_test_same(count($models), 1);
    openai_profile_test_same($models[0]->getId(), 'profile-model');
    openai_profile_test_same(
        $transporterA->request instanceof Request ? $transporterA->request->getUri() : '',
        'https://profile.example/v1/models'
    );
    openai_profile_test_true(in_array('parse-models', $traceA->events, true));

    $traceA2 = new OpenAiProfileTestTrace();
    $profileA2 = new OpenAiProfileTestProfile(
        'tenant-sensitive-value-alpha',
        [OpenAiApiOperation::LIST_MODELS],
        $traceA2
    );
    $directoryA2 = new OpenAiProfileTestMetadataDirectory();
    $directoryA2->setRequestAuthentication(
        new OpenAiProfileTestAuthentication($profileA2, $traceA2)
    );

    $traceB = new OpenAiProfileTestTrace();
    $profileB = new OpenAiProfileTestProfile(
        'tenant-sensitive-value-beta',
        [OpenAiApiOperation::LIST_MODELS],
        $traceB
    );
    $directoryB = new OpenAiProfileTestMetadataDirectory();
    $directoryB->setRequestAuthentication(
        new OpenAiProfileTestAuthentication($profileB, $traceB)
    );

    $directoryStandard = new OpenAiProfileTestMetadataDirectory();
    $directoryStandard->setRequestAuthentication(new ApiKeyRequestAuthentication('standard-key'));

    openai_profile_test_same($directoryA->baseCacheKey(), $directoryA2->baseCacheKey());
    openai_profile_test_true($directoryA->baseCacheKey() !== $directoryB->baseCacheKey());
    openai_profile_test_true($directoryA->baseCacheKey() !== $directoryStandard->baseCacheKey());
    openai_profile_test_same(
        strpos($directoryA->baseCacheKey(), 'tenant-sensitive-value-alpha'),
        false,
        'Profile cache identity must be hashed before entering the cache key'
    );
    openai_profile_test_same(
        $authenticationA->profileResolutionCount,
        1,
        'Catalog cache, request, and parsing must share one pinned profile'
    );
};

$tests['unsupported image operation fails before transport'] = static function (): void {
    openai_profile_test_reset();
    $trace = new OpenAiProfileTestTrace();
    $profile = new OpenAiProfileTestProfile(
        'text-only-profile',
        [OpenAiApiOperation::LIST_MODELS, OpenAiApiOperation::GENERATE_TEXT],
        $trace
    );
    $transporter = new OpenAiProfileTestTransporter(new Response(200, [], '{}'), $trace);
    $model = new OpenAiImageGenerationModel(
        openai_profile_test_model_metadata('image-profile', CapabilityEnum::imageGeneration()),
        openai_profile_test_provider_metadata()
    );
    $authentication = new OpenAiProfileTestAuthentication($profile, $trace);
    $model->setRequestAuthentication($authentication);
    $model->setHttpTransporter($transporter);

    $thrown = false;
    try {
        $model->generateImageResult(openai_profile_test_prompt());
    } catch (AiRuntimeException $exception) {
        $message = $exception->getMessage();
        $thrown = strpos($message, 'support') !== false
            && strpos($message, 'image generation') !== false;
    }

    openai_profile_test_true($thrown, 'Unsupported image generation must throw a runtime exception.');
    openai_profile_test_same($transporter->sendCount, 0, 'Unsupported operations must not reach transport.');
    openai_profile_test_same(in_array('authenticate', $trace->events, true), false);
    openai_profile_test_same($authentication->profileResolutionCount, 1);
};

$tests['profile-backed image lifecycle uses one pinned profile'] = static function (): void {
    openai_profile_test_reset();
    $trace = new OpenAiProfileTestTrace();
    $profile = new OpenAiProfileTestProfile(
        'profile-image',
        [OpenAiApiOperation::GENERATE_IMAGE],
        $trace
    );
    $profile->setNormalizedResponse(
        openai_profile_test_image_response('normalized-profile-image')
    );
    $authentication = new OpenAiProfileTestAuthentication($profile, $trace);
    $transporter = new OpenAiProfileTestTransporter(
        new Response(200, ['Content-Type' => 'application/x-profile-image'], '{"image":"raw"}'),
        $trace
    );
    $model = new OpenAiImageGenerationModel(
        openai_profile_test_model_metadata('gpt-image-profile', CapabilityEnum::imageGeneration()),
        openai_profile_test_provider_metadata()
    );
    $model->setRequestAuthentication($authentication);
    $model->setHttpTransporter($transporter);

    $result = $model->generateImageResult(openai_profile_test_prompt());
    openai_profile_test_same($result->getId(), 'img-1234567890');
    openai_profile_test_same(
        $result->toFile()->getBase64Data(),
        base64_encode('normalized-profile-image')
    );
    openai_profile_test_same($transporter->sendCount, 1);
    openai_profile_test_same($authentication->profileResolutionCount, 1);
    openai_profile_test_same(
        $trace->events,
        [
            'url:' . OpenAiApiOperation::GENERATE_IMAGE,
            'prepare:' . OpenAiApiOperation::GENERATE_IMAGE,
            'authenticate',
            'transport',
            'normalize:' . OpenAiApiOperation::GENERATE_IMAGE,
        ]
    );
    openai_profile_test_same(
        $transporter->request instanceof Request ? $transporter->request->getUri() : '',
        'https://profile.example/v1/images/generations'
    );
    openai_profile_test_same(
        $transporter->request instanceof Request
            ? $transporter->request->getHeaderAsString('X-Profile-Prepared')
            : null,
        OpenAiApiOperation::GENERATE_IMAGE
    );
    openai_profile_test_same(
        $profile->urlCalls,
        [[
            'defaultUrl' => 'https://api.openai.com/v1/images/generations',
            'path' => 'images/generations',
            'operation' => OpenAiApiOperation::GENERATE_IMAGE,
        ]]
    );
};

$tests['profile cache identity failures fail closed'] = static function (): void {
    openai_profile_test_reset();
    $trace = new OpenAiProfileTestTrace();
    $profile = new OpenAiProfileTestProfile(
        'must-not-be-shared',
        [OpenAiApiOperation::LIST_MODELS],
        $trace
    );
    $profile->failCacheKeyLookup();
    $authentication = new OpenAiProfileTestAuthentication($profile, $trace);
    $directory = new OpenAiProfileTestMetadataDirectory();
    $directory->setRequestAuthentication($authentication);

    $thrown = false;
    try {
        $directory->baseCacheKey();
    } catch (\RuntimeException $exception) {
        $thrown = strpos($exception->getMessage(), 'cache identity') !== false;
    }

    openai_profile_test_true(
        $thrown,
        'A profile cache identity failure must not fall back to a shared cache key'
    );
    openai_profile_test_same($authentication->profileResolutionCount, 1);
};

$tests['resolver filter accepts profiles and rejects invalid values'] = static function (): void {
    openai_profile_test_reset();
    $authentication = new ApiKeyRequestAuthentication('filter-test-key');
    $trace = new OpenAiProfileTestTrace();
    $filteredProfile = new OpenAiProfileTestProfile(
        'filtered-profile',
        [OpenAiApiOperation::GENERATE_TEXT],
        $trace
    );
    $filterSawAuthentication = false;
    $GLOBALS['openai_profile_test_filters']['ai_provider_for_openai_api_profile'][] =
        static function (
            $profile,
            $receivedAuthentication
        ) use (
            $authentication,
            $filteredProfile,
            &$filterSawAuthentication
        ) {
            openai_profile_test_same($profile, null);
            $filterSawAuthentication = $receivedAuthentication === $authentication;

            return $filteredProfile;
        };

    openai_profile_test_same(
        OpenAiApiProfileResolver::resolve($authentication),
        $filteredProfile
    );
    openai_profile_test_true($filterSawAuthentication, 'The resolver must pass authentication to the filter.');

    openai_profile_test_reset();
    $GLOBALS['openai_profile_test_filters']['ai_provider_for_openai_api_profile'][] =
        static function () {
            return new stdClass();
        };

    $thrown = false;
    try {
        OpenAiApiProfileResolver::resolve($authentication);
    } catch (AiRuntimeException $exception) {
        $thrown = strpos($exception->getMessage(), 'OpenAiApiProfileInterface') !== false;
    }
    openai_profile_test_true($thrown, 'Invalid filtered profiles must fail validation.');
};

$failures = [];
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $throwable) {
        $failures[] = $name . ': ' . $throwable->getMessage();
        fwrite(STDERR, "FAIL {$name}: {$throwable->getMessage()}\n");
    }
}

if ($failures !== []) {
    exit(1);
}

fwrite(STDOUT, sprintf("%d integration profile contract tests passed.\n", count($tests)));
