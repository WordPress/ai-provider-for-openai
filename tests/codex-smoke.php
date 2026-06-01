<?php

declare(strict_types=1);

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\OpenAiAiProvider\Codex\CodexProvider;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/autoload.php';

$codexSmokeTokens = [
    'access_token' => 'expired-access-token',
    'refresh_token' => 'test-refresh-token',
    'expires_at' => time() - 3600,
    'account_id' => 'test-account-id',
    'fedramp' => true,
];
$codexSmokeRefreshCalls = 0;
$codexSmokeUpdatedTokens = null;
$codexSmokeAutoload = null;
$codexSmokeRequestConnectTimeouts = [];

function get_option(string $option, $default = false)
{
    global $codexSmokeTokens;

    if ($option !== 'ai_provider_openai_codex_oauth_tokens') {
        return $default;
    }

    return $codexSmokeTokens;
}

function update_option(string $option, $value, $autoload = null): bool
{
    global $codexSmokeAutoload, $codexSmokeTokens, $codexSmokeUpdatedTokens;

    assert($option === 'ai_provider_openai_codex_oauth_tokens');
    $codexSmokeTokens = $value;
    $codexSmokeUpdatedTokens = $value;
    $codexSmokeAutoload = $autoload;

    return true;
}

function wp_remote_post(string $url, array $args = [])
{
    global $codexSmokeRefreshCalls;

    ++$codexSmokeRefreshCalls;

    assert($url === 'https://auth.openai.com/oauth/token');
    assert(($args['body']['grant_type'] ?? null) === 'refresh_token');
    assert(($args['body']['client_id'] ?? null) === 'app_EMoamEEZ73f0CkXaXp7hrann');
    assert(($args['body']['refresh_token'] ?? null) === 'test-refresh-token');

    return [
        'response' => ['code' => 200],
        'body' => json_encode(
            [
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'expires_in' => 3600,
            ]
        ),
    ];
}

function wp_remote_retrieve_response_code($response): int
{
    return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body($response): string
{
    return (string) ($response['body'] ?? '');
}

function is_wp_error($response): bool
{
    return false;
}

$registry = new ProviderRegistry();
$registry->setHttpTransporter(
    new class implements HttpTransporterInterface {
        public function send(Request $request, ?RequestOptions $options = null): Response
        {
            global $codexSmokeRequestConnectTimeouts;

            $requestOptions = $request->getOptions();

            assert($request->getUri() === 'https://chatgpt.com/backend-api/codex/responses');
            assert(in_array($request->getHeaderAsString('Authorization'), ['Bearer fresh-access-token', 'Bearer env-access-token'], true));
            assert(in_array($request->getHeaderAsString('ChatGPT-Account-ID'), ['test-account-id', 'env-account-id'], true));
            assert($request->getHeaderAsString('X-OpenAI-Fedramp') === 'true');
            assert($requestOptions instanceof RequestOptions);
            $codexSmokeRequestConnectTimeouts[] = $requestOptions->getConnectTimeout();

            $data = $request->getData();
            assert(is_array($data));
            assert(($data['model'] ?? null) === 'gpt-5.5');
            assert(($data['store'] ?? null) === false);
            assert(($data['stream'] ?? null) === true);
            assert(isset($data['instructions']) && is_string($data['instructions']));

            $body = implode(
                "\n\n",
                [
                    'data: {"type":"response.output_text.delta","delta":"codex "}',
                    'data: {"delta":"smoke"}',
                    'data: {"type":"response.completed","response":{"id":"resp_test","usage":{"input_tokens":1,"output_tokens":2,"total_tokens":3}}}',
                    'data: [DONE]',
                ]
            );

            return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
        }
    }
);

$registry->registerProvider(CodexProvider::class);

assert($registry->isProviderConfigured('codex') === true);

$model = $registry->getProviderModel('codex', 'gpt-5.5');
$result = $model->generateTextResult([new UserMessage([new MessagePart('hello')])]);

assert($result->toText() === 'codex smoke');
assert($codexSmokeRequestConnectTimeouts[0] === 120.0);
assert($codexSmokeRefreshCalls === 1);
assert(is_array($codexSmokeUpdatedTokens));
assert(($codexSmokeUpdatedTokens['access_token'] ?? null) === 'fresh-access-token');
assert(($codexSmokeUpdatedTokens['refresh_token'] ?? null) === 'fresh-refresh-token');
assert($codexSmokeAutoload === false);

echo "Codex smoke passed.\n";

$codexSmokeTokens = [];
putenv('AI_PROVIDER_OPENAI_CODEX_ACCESS_TOKEN=env-access-token');
putenv('AI_PROVIDER_OPENAI_CODEX_REFRESH_TOKEN=env-refresh-token');
putenv('AI_PROVIDER_OPENAI_CODEX_EXPIRES_AT=' . (time() + 3600));
putenv('AI_PROVIDER_OPENAI_CODEX_ACCOUNT_ID=env-account-id');
putenv('AI_PROVIDER_OPENAI_CODEX_FEDRAMP=true');

$envTokenStore = new WordPress\OpenAiAiProvider\Codex\CodexTokenStore();
$envTokens = $envTokenStore->getTokens();

assert(($envTokens['access_token'] ?? null) === 'env-access-token');
assert(($envTokens['refresh_token'] ?? null) === 'env-refresh-token');
assert(($envTokens['account_id'] ?? null) === 'env-account-id');
assert(($envTokens['fedramp'] ?? null) === true);

echo "Codex env token smoke passed.\n";

$shortOptions = new RequestOptions();
$shortOptions->setTimeout(60.0);
$shortOptions->setConnectTimeout(15.0);
$model->setRequestOptions($shortOptions);
$result = $model->generateTextResult([new UserMessage([new MessagePart('hello again')])]);

assert($result->toText() === 'codex smoke');
assert($codexSmokeRequestConnectTimeouts[1] === 60.0);

echo "Codex request timeout floor smoke passed.\n";
