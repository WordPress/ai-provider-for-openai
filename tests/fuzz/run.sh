#!/usr/bin/env bash

set -euo pipefail

mode="${1:-metadata}"
client_path="${2:-${PHP_AI_CLIENT_PATH:-}}"

if [[ "$mode" != "metadata" && "$mode" != "live" ]]; then
    printf 'Usage: %s metadata|live /path/to/php-ai-client\n' "$0" >&2
    exit 2
fi

if [[ -z "$client_path" || ! -d "$client_path" ]]; then
    printf 'Provide the php-ai-client PR checkout as the second argument or PHP_AI_CLIENT_PATH.\n' >&2
    exit 2
fi

if [[ "$mode" == "live" && -z "${OPENAI_API_KEY:-}" ]]; then
    printf 'OPENAI_API_KEY is required for live mode.\n' >&2
    exit 2
fi

provider_root="$(realpath "$(dirname "$0")/../..")"
client_root="$(realpath "$client_path")"
if [[ ! -f "$client_root/vendor/autoload.php" ]]; then
    composer install --working-dir="$client_root" --no-interaction --prefer-dist
fi
probe="$provider_root/tests/fuzz/openai-model-metadata.php"
if [[ "$mode" == "live" ]]; then
    PHP_AI_CLIENT_PATH="$client_root" php "$provider_root/tests/fuzz/openai-live-generation.php"
    exit
fi

suite_file="$(mktemp)"
trap 'rm -f "$suite_file"' EXIT
artifacts_dir="${WP_CODEBOX_ARTIFACTS:-$(mktemp -d)}"

jq -n \
    --arg mode "$mode" \
    --arg providerRoot "$provider_root" \
    --arg clientRoot "$client_root" \
    --arg probe "$probe" \
    '{
        schema: "wp-codebox/fuzz-suite/v1",
        id: ("php-ai-client-openai-" + $mode),
        version: "1",
        target: {kind: "runtime", entrypoint: "wordpress.run-workload"},
        cases: [{
            id: ($mode + "-contract"),
            input: {
                schema: "wp-codebox/wordpress-workload-run/v1",
                id: ("openai-" + $mode),
                steps: [{command: "wordpress.run-workload", args: ["type=php", ("path=" + $probe)]}]
            }
        }],
        metadata: {runtime_requirements: {
            extra_plugins: [
                {source: $providerRoot, slug: "ai-provider-for-openai", pluginFile: "ai-provider-for-openai/plugin.php", activate: false}
            ],
            runtime_mounts: [
                {type: "directory", source: $clientRoot, target: "/wordpress/wp-content/plugins/php-ai-client-pr", mode: "readonly"}
            ]
        }}
    }' > "$suite_file"

args=(
    run-fuzz-suite
    --input-file "$suite_file"
    --runner-mode=runtime-backed
    --artifacts "$artifacts_dir"
    --format=json
)

wp-codebox "${args[@]}"
