# OpenAI metadata fuzz coverage

These workloads exercise the explicit-model metadata path against the matching
PHP AI Client pull request in a disposable WP Codebox WordPress Playground.
The runner installs the PHP AI Client checkout's Composer dependencies when
needed. WP Codebox artifacts go to a temporary directory unless
`WP_CODEBOX_ARTIFACTS` is set.

Run the deterministic metadata and support-contract corpus:

```bash
./tests/fuzz/run.sh metadata ../php-ai-client
```

Run one bounded live generation request locally. The key remains in the
calling process environment and is not copied into WP Codebox artifacts:

```bash
OPENAI_API_KEY=... ./tests/fuzz/run.sh live ../php-ai-client
```

The metadata workload covers basic and configured text support, chat history,
multimodal boundaries, listed/direct metadata equality, non-text fallback,
public direct lookup without a models request, empty batches, duplicate IDs,
and deterministic generated model ID families.
