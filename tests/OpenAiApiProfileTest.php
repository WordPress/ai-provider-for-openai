<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Tests;

// Profile fixtures share this contract test file.
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

use PHPUnit\Framework\TestCase;
use stdClass;
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
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\OpenAiAiProvider\Authentication\OpenAiApiProfileRequestAuthenticationAdapter;
use WordPress\OpenAiAiProvider\Contracts\OpenAiApiProfileAwareAuthenticationInterface;
use WordPress\OpenAiAiProvider\Contracts\OpenAiApiProfileInterface;
use WordPress\OpenAiAiProvider\Metadata\OpenAiModelMetadataDirectory;
use WordPress\OpenAiAiProvider\Models\OpenAiEmbeddingGenerationModel;
use WordPress\OpenAiAiProvider\Models\OpenAiImageGenerationModel;
use WordPress\OpenAiAiProvider\Models\OpenAiTextGenerationModel;
use WordPress\OpenAiAiProvider\Models\OpenAiTextToSpeechConversionModel;
use WordPress\OpenAiAiProvider\Provider\OpenAiApiOperation;
use WordPress\OpenAiAiProvider\Provider\OpenAiApiProfileResolver;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

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
 * Profile-aware authentication that is intentionally unrelated to API keys.
 */
final class OpenAiProfileTestDelegatedAuthentication implements
    OpenAiApiProfileAwareAuthenticationInterface
{
    private OpenAiApiProfileInterface $profile;

    public function __construct(OpenAiApiProfileInterface $profile)
    {
        $this->profile = $profile;
    }

    public function getOpenAiApiProfile(): OpenAiApiProfileInterface
    {
        return $this->profile;
    }

    public function authenticateRequest(Request $request): Request
    {
        return $request->withHeader('Authorization', 'Bearer delegated-profile-token');
    }

    /** @return array<string, mixed> */
    public static function getJsonSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
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

    /** @return list<ModelMetadata> */
    public function parseModels(Response $response): array
    {
        return $this->parseResponseToModelMetadataList($response);
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
    TestCase::assertSame($expected, $actual, $message);
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

/**
 * Tests the OpenAI API profile lifecycle and registry compatibility.
 *
 * @since n.e.x.t
 */
final class OpenAiApiProfileTest extends TestCase
{
    protected function tearDown(): void
    {
        $GLOBALS['openai_profile_test_filters'] = [];
        parent::tearDown();
    }

    public function testUnboundMetadataParsingAndCacheLookupDoNotPinAProfile(): void
    {
        openai_profile_test_reset();
        $directory = new OpenAiProfileTestMetadataDirectory();
        $unboundCacheKey = $directory->baseCacheKey();
        $models = $directory->parseModels(new Response(200, [], '{"data":[{"id":"gpt-4o"}]}'));
        self::assertSame('gpt-4o', $models[0]->getId());

        $trace = new OpenAiProfileTestTrace();
        $profile = new OpenAiProfileTestProfile('bound-after-parsing', [OpenAiApiOperation::LIST_MODELS], $trace);
        $profile->setParsedModels([
            openai_profile_test_model_metadata('alternate-model', CapabilityEnum::textGeneration()),
        ]);
        $authentication = new OpenAiProfileTestAuthentication($profile, $trace);
        $directory->setRequestAuthentication($authentication);
        self::assertNotSame($unboundCacheKey, $directory->baseCacheKey());
        self::assertSame('alternate-model', $directory->parseModels(new Response(200, [], '{}'))[0]->getId());
        self::assertSame(1, $authentication->profileResolutionCount);

        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication('standard'));
        self::assertSame($unboundCacheKey, $directory->baseCacheKey());
    }

    /** @return array<string, array{string, string, string, string}> */
    public function additionalOperations(): array
    {
        return [
            'embeddings' => [
                OpenAiApiOperation::GENERATE_EMBEDDING, 'embeddings',
                OpenAiEmbeddingGenerationModel::class, 'generateEmbeddingResult',
            ],
            'speech' => [
                OpenAiApiOperation::CONVERT_TEXT_TO_SPEECH, 'audio/speech',
                OpenAiTextToSpeechConversionModel::class, 'convertTextToSpeechResult',
            ],
        ];
    }

    /**
     * @dataProvider additionalOperations
     */
    public function testAdditionalOperationUsesProfileLifecycle(
        string $operation,
        string $path,
        string $class,
        string $method
    ): void {
        $this->requireOperationSupport($operation);
        openai_profile_test_reset();
        $trace = new OpenAiProfileTestTrace();
        $profile = new OpenAiProfileTestProfile('additional-operation', [$operation], $trace);
        $normalized = $operation === OpenAiApiOperation::GENERATE_EMBEDDING
            ? new Response(200, [], '{"data":[{"embedding":[0.25,0.75],"index":0}]}')
            : new Response(200, ['Content-Type' => 'audio/mpeg'], 'normalized-audio');
        $profile->setNormalizedResponse($normalized);
        $authentication = new OpenAiProfileTestAuthentication($profile, $trace);
        $transporter = new OpenAiProfileTestTransporter(new Response(200, [], 'alternate payload'), $trace);
        $capability = $operation === OpenAiApiOperation::GENERATE_EMBEDDING
            ? CapabilityEnum::embeddingGeneration() : CapabilityEnum::textToSpeechConversion();
        $model = new $class(
            openai_profile_test_model_metadata('test-model', $capability),
            openai_profile_test_provider_metadata()
        );
        $model->setRequestAuthentication($authentication);
        $model->setHttpTransporter($transporter);
        $input = $operation === OpenAiApiOperation::GENERATE_EMBEDDING
            ? [new MessagePart('Hello')] : openai_profile_test_prompt();
        $result = $model->$method($input);
        self::assertSame('https://profile.example/v1/' . $path, $transporter->request->getUri());
        self::assertSame($operation, $transporter->request->getHeaderAsString('X-Profile-Prepared'));
        self::assertSame('Bearer profile-test-key', $transporter->request->getHeaderAsString('Authorization'));
        self::assertSame(
            ['url:' . $operation, 'prepare:' . $operation, 'authenticate', 'transport', 'normalize:' . $operation],
            $trace->events
        );
        self::assertSame(1, $authentication->profileResolutionCount);
        if ($operation === OpenAiApiOperation::GENERATE_EMBEDDING) {
            self::assertSame([0.25, 0.75], $result->getEmbeddings()[0]->getValues());
        } else {
            self::assertSame(base64_encode('normalized-audio'), $result->toFile()->getBase64Data());
        }
    }

    /**
     * @dataProvider additionalOperations
     */
    public function testUnsupportedAdditionalOperationStopsBeforeAuthentication(
        string $operation,
        string $path,
        string $class,
        string $method
    ): void {
        unset($path);
        $this->requireOperationSupport($operation);
        openai_profile_test_reset();
        $trace = new OpenAiProfileTestTrace();
        $profile = new OpenAiProfileTestProfile('text-only', [OpenAiApiOperation::GENERATE_TEXT], $trace);
        $authentication = new OpenAiProfileTestAuthentication($profile, $trace);
        $transporter = new OpenAiProfileTestTransporter(new Response(200, [], '{}'), $trace);
        $capability = $operation === OpenAiApiOperation::GENERATE_EMBEDDING
            ? CapabilityEnum::embeddingGeneration() : CapabilityEnum::textToSpeechConversion();
        $model = new $class(
            openai_profile_test_model_metadata('test-model', $capability),
            openai_profile_test_provider_metadata()
        );
        $model->setRequestAuthentication($authentication);
        $model->setHttpTransporter($transporter);
        $input = $operation === OpenAiApiOperation::GENERATE_EMBEDDING
            ? [new MessagePart('Hello')] : openai_profile_test_prompt();
        try {
            $model->$method($input);
            self::fail('Unsupported profile operations must fail.');
        } catch (AiRuntimeException $exception) {
            self::assertStringContainsString('support', $exception->getMessage());
        }
        self::assertSame([], $trace->events);
        self::assertSame(0, $transporter->sendCount);
    }

    /**
     * @dataProvider additionalOperations
     */
    public function testAdditionalOperationCannotNormalizeAnHttpFailure(
        string $operation,
        string $path,
        string $class,
        string $method
    ): void {
        unset($path);
        $this->requireOperationSupport($operation);
        openai_profile_test_reset();
        $trace = new OpenAiProfileTestTrace();
        $profile = new OpenAiProfileTestProfile('http-failure', [$operation], $trace);
        $profile->setNormalizedResponse(new Response(200, [], '{}'));
        $transporter = new OpenAiProfileTestTransporter(
            new Response(403, [], '{"error":{"message":"forbidden"}}'),
            $trace
        );
        $capability = $operation === OpenAiApiOperation::GENERATE_EMBEDDING
            ? CapabilityEnum::embeddingGeneration() : CapabilityEnum::textToSpeechConversion();
        $model = new $class(
            openai_profile_test_model_metadata('test-model', $capability),
            openai_profile_test_provider_metadata()
        );
        $model->setRequestAuthentication(new OpenAiProfileTestAuthentication($profile, $trace));
        $model->setHttpTransporter($transporter);
        $input = $operation === OpenAiApiOperation::GENERATE_EMBEDDING
            ? [new MessagePart('Hello')] : openai_profile_test_prompt();
        try {
            $model->$method($input);
            self::fail('An unsuccessful HTTP response must throw.');
        } catch (\WordPress\AiClient\Providers\Http\Exception\ClientException $exception) {
            self::assertNotEmpty($exception->getMessage());
        }
        self::assertSame(
            ['url:' . $operation, 'prepare:' . $operation, 'authenticate', 'transport'],
            $trace->events
        );
    }

    private function requireOperationSupport(string $operation): void
    {
        if (
            $operation === OpenAiApiOperation::GENERATE_EMBEDDING
            && !interface_exists(EmbeddingGenerationModelInterface::class)
        ) {
            self::markTestSkipped('Embedding generation requires PHP AI Client 1.4.0 or later.');
        }
        if (
            $operation === OpenAiApiOperation::CONVERT_TEXT_TO_SPEECH
            && !interface_exists(TextToSpeechConversionModelInterface::class)
        ) {
            self::markTestSkipped('The installed SDK does not support text-to-speech.');
        }
    }

    public function testProfileAuthenticationAdapterSatisfiesTheRegistryTypeContract(): void
    {
        openai_profile_test_reset();
        $profile = new OpenAiProfileTestProfile(
            'profile-adapter',
            [OpenAiApiOperation::GENERATE_TEXT],
            new OpenAiProfileTestTrace()
        );
        $delegate = new OpenAiProfileTestDelegatedAuthentication($profile);
        $authentication = new OpenAiApiProfileRequestAuthenticationAdapter($delegate);

        openai_profile_test_true($authentication instanceof ApiKeyRequestAuthentication);
        openai_profile_test_same(
            OpenAiApiProfileResolver::resolve($authentication),
            $profile
        );

        $request = new Request(
            \WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum::GET(),
            'https://profile-adapter.example/models'
        );
        $authenticatedRequest = $authentication->authenticateRequest($request);
        openai_profile_test_same(
            $authenticatedRequest->getHeaderAsString('Authorization'),
            'Bearer delegated-profile-token'
        );
        openai_profile_test_same(
            strpos($authenticatedRequest->getHeaderAsString('Authorization'), 'managed-authentication'),
            false
        );

        $registry = new ProviderRegistry();
        $registry->registerProvider(OpenAiProvider::class);
        try {
            $registry->setProviderRequestAuthentication(OpenAiProvider::class, $authentication);
            openai_profile_test_same(
                $registry->getProviderRequestAuthentication(OpenAiProvider::class),
                $authentication
            );
            openai_profile_test_same(
                OpenAiProvider::modelMetadataDirectory()->getRequestAuthentication(),
                $authentication
            );
        } finally {
            $registry->setProviderRequestAuthentication(
                OpenAiProvider::class,
                new ApiKeyRequestAuthentication('profile-adapter-test-reset-key')
            );
        }
    }

    public function testProfileAuthenticationAdapterCannotBeSerializedAsAnApiKey(): void
    {
        $profile = new OpenAiProfileTestProfile('serialization', [], new OpenAiProfileTestTrace());
        $authentication = new OpenAiApiProfileRequestAuthenticationAdapter(
            new OpenAiProfileTestDelegatedAuthentication($profile)
        );

        $this->expectException(AiRuntimeException::class);
        $authentication->toArray();
    }

    public function testPlainApiKeyBehaviorRemainsUnchanged(): void
    {
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
    }

    public function testProfilePreparesBeforeAuthAndNormalizesSuccessfulResponse(): void
    {
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
    }

    public function testAlternateModelCatalogParsingAndCacheIsolation(): void
    {
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
    }

    public function testUnsupportedImageOperationFailsBeforeTransport(): void
    {
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
    }

    public function testProfileBackedImageLifecycleUsesOnePinnedProfile(): void
    {
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
    }

    public function testProfileCacheIdentityFailuresFailClosed(): void
    {
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
    }

    public function testResolverFilterAcceptsProfilesAndRejectsInvalidValues(): void
    {
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
    }
}
