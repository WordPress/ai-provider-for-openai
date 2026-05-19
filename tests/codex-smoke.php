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

define('AI_PROVIDER_OPENAI_CODEX_ACCESS_TOKEN', 'test-access-token');
define('AI_PROVIDER_OPENAI_CODEX_EXPIRES_AT', time() + 3600);
define('AI_PROVIDER_OPENAI_CODEX_ACCOUNT_ID', 'test-account-id');

$registry = new ProviderRegistry();
$registry->setHttpTransporter(
    new class implements HttpTransporterInterface {
        public function send(Request $request, ?RequestOptions $options = null): Response
        {
            assert($request->getUri() === 'https://chatgpt.com/backend-api/codex/responses');
            assert($request->getHeaderAsString('Authorization') === 'Bearer test-access-token');
            assert($request->getHeaderAsString('ChatGPT-Account-ID') === 'test-account-id');

            $data = $request->getData();
            assert(is_array($data));
            assert(($data['model'] ?? null) === 'gpt-5.5');
            assert(($data['store'] ?? null) === false);
            assert(($data['stream'] ?? null) === true);
            assert(isset($data['instructions']) && is_string($data['instructions']));

            $body = implode(
                "\n\n",
                [
                    'data: {"delta":"codex "}',
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

$model = $registry->getProviderModel('codex', 'gpt-5.5');
$result = $model->generateTextResult([new UserMessage([new MessagePart('hello')])]);

assert($result->toText() === 'codex smoke');

echo "Codex smoke passed.\n";
