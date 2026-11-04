<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Perplexity\Agent\AgentClient;
use Prism\Perplexity\Agent\AgentProtocol;
use Prism\Perplexity\Agent\AgentStatus;
use Prism\Perplexity\Agent\AgentWaitTimedOut;
use Prism\Perplexity\Perplexity;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\PrismManager;

function agentPerplexity(): Perplexity
{
    /** @var Perplexity */
    return app(PrismManager::class)->resolve('perplexity');
}

/** The transport options the AgentClient was actually handed. */
function agentTransportOptions(AgentClient $client): array
{
    $clientProperty = new ReflectionProperty($client, 'client');
    $pending = $clientProperty->getValue($client);

    $optionsProperty = new ReflectionProperty($pending, 'options');

    return $optionsProperty->getValue($pending);
}

it('gives the long-running endpoint a timeout it can actually finish inside', function (): void {
    // `agent()` built its client with NO options at all, so the transport kept
    // Laravel's 30-second default -- on the one endpoint whose purpose is to run
    // for minutes. A routine deep-research call was measured at 57-59 seconds,
    // and the failure was cURL 28 with zero bytes received, which reads like a
    // network hang rather than a ceiling that was always going to fire.
    // Reported as #2.
    //
    // Asserted on the transport rather than through a faked response, because a
    // fake never times out: a test that sends a request and checks it arrived
    // would pass identically with no timeout set, which is the vacuous shape
    // this package's own tests are written to avoid.
    $options = agentTransportOptions(agentPerplexity()->agent());

    expect($options['timeout'])->toBe(300);
});

it('lets the caller override the agent timeout', function (): void {
    // The half that could not be worked around from outside the package. A
    // default alone would leave every caller stuck with whatever we chose, and
    // the reporter could not reach this knob at all.
    $options = agentTransportOptions(agentPerplexity()->agent(null, ['timeout' => 900]));

    expect($options['timeout'])->toBe(900);
});

it('keeps other caller options alongside the default timeout', function (): void {
    // array_replace, not a bare default: an option the caller sets that is not
    // `timeout` must survive, and the default must still apply to what they
    // left out.
    $options = agentTransportOptions(agentPerplexity()->agent(null, ['connect_timeout' => 5]));

    expect($options['connect_timeout'])->toBe(5)
        ->and($options['timeout'])->toBe(300);
});

it('creates a durable agent response with lossless options and metadata', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::response([
        'id' => 'resp_123', 'status' => 'queued', 'model' => 'openai/gpt-5.4',
        'created_at' => 123, 'output' => [],
        'usage' => ['input_tokens' => 2, 'cost' => ['currency' => 'USD', 'total_cost' => 0.01]],
    ])]);

    $response = agentPerplexity()->agent()->create('Research Prism', [
        'models' => ['openai/gpt-5.4', 'anthropic/claude-sonnet-4-6'],
        'max_steps' => 8,
        'store' => true,
        'reasoning' => ['effort' => 'high'],
        'skills' => [['type' => 'browser']],
        'tools' => [['type' => 'web_search', 'filters' => ['search_domain_filter' => ['prismphp.com']]]],
    ]);

    expect($response->protocol)->toBe(AgentProtocol::Agent)
        ->and($response->status)->toBe(AgentStatus::Queued)
        ->and($response->usage['cost']['total_cost'])->toBe(0.01)
        ->and($response->isTerminal())->toBeFalse();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.perplexity.ai/v1/agent'
        && $request->data()['background'] === true
        && $request->data()['max_steps'] === 8
        && $request->data()['tools'][0]['filters']['search_domain_filter'] === ['prismphp.com']);
});

it('retrieves and maps a completed response without losing annotations', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::response([
        'id' => 'resp_123', 'status' => 'completed', 'model' => 'anthropic/claude-sonnet-4-6',
        'created_at' => 123,
        'output' => [['type' => 'message', 'content' => [[
            'type' => 'output_text', 'text' => 'Done.',
            'annotations' => [['type' => 'url_citation', 'url' => 'https://prismphp.com']],
        ]]]],
        'usage' => ['input_tokens' => 2, 'output_tokens' => 1, 'tool_calls_details' => ['web_search' => 1]],
    ])]);

    $response = agentPerplexity()->agent()->retrieve('resp_123');

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->text())->toBe('Done.')
        ->and($response->annotations[0]['url'])->toBe('https://prismphp.com')
        ->and($response->usage['tool_calls_details']['web_search'])->toBe(1);
});

it('waits only to the configured bound', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::response([
        'id' => 'resp_123', 'status' => 'in_progress', 'model' => 'openai/gpt-5.4', 'output' => [],
    ])]);

    expect(fn () => agentPerplexity()->agent(static function (int $milliseconds): void {})
        ->wait('resp_123', maxAttempts: 3, intervalMilliseconds: 25))
        ->toThrow(AgentWaitTimedOut::class);

    Http::assertSentCount(3);
});

it('returns typed failures and cancellation as lifecycle results', function (string $status): void {
    Http::fake(['api.perplexity.ai/*' => Http::response([
        'id' => 'resp_123', 'status' => $status, 'model' => 'openai/gpt-5.4', 'output' => [],
        'error' => ['type' => 'agent_error', 'code' => 'stopped', 'message' => 'The run stopped.'],
    ])]);

    $response = agentPerplexity()->agent()->retrieve('resp_123');

    expect($response->isTerminal())->toBeTrue()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->error?->code)->toBe('stopped');
})->with(['failed', 'cancelled', 'incomplete']);

it('reads why a run stopped early, and reports no reason rather than guessing', function (array $details, ?string $expected): void {
    Http::fake(['api.perplexity.ai/*' => Http::response([
        'id' => 'resp_123', 'status' => 'incomplete', 'output' => [], ...$details,
    ])]);

    expect(agentPerplexity()->agent()->retrieve('resp_123')->incompleteReason())->toBe($expected);
})->with([
    'reason given' => [['incomplete_details' => ['reason' => 'max_output_tokens']], 'max_output_tokens'],
    'no details' => [[], null],
    'null details' => [['incomplete_details' => null], null],
    'non-string reason' => [['incomplete_details' => ['reason' => ['nested']]], null],
]);

it('cancels through the documented endpoint', function (): void {
    // The documented 200 body, exactly: only response_id and status, no id, model, output or usage.
    Http::fake(['api.perplexity.ai/*' => Http::response(['response_id' => 'resp_123', 'status' => 'cancelling'])]);

    $response = agentPerplexity()->agent()->cancel('resp_123');

    // Cancellation is asynchronous: a 200 says it has started, not that it finished.
    expect($response->id)->toBe('resp_123')
        ->and($response->status)->toBe(AgentStatus::Cancelling)
        ->and($response->isTerminal())->toBeFalse()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->model)->toBeNull()
        ->and($response->output)->toBe([])
        ->and($response->usage)->toBe([])
        ->and($response->raw)->toBe(['response_id' => 'resp_123', 'status' => 'cancelling']);
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/agent/resp_123/cancel'));
});

it('keeps waiting through cancelling until the run is cancelled', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::sequence()
        ->push(['id' => 'resp_123', 'status' => 'cancelling', 'output' => []])
        ->push(['id' => 'resp_123', 'status' => 'cancelled', 'output' => []])]);

    $response = agentPerplexity()->agent(fn (int $milliseconds) => null)->wait('resp_123', maxAttempts: 2);

    expect($response->status)->toBe(AgentStatus::Cancelled)
        ->and($response->isTerminal())->toBeTrue();
    Http::assertSentCount(2);
});

it('refuses a cancel acknowledgement with no response identifier', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::response(['status' => 'cancelling'])]);

    expect(fn () => agentPerplexity()->agent()->cancel('resp_123'))->toThrow(PrismException::class, 'invalid_response');
});

it('reads response_id only on cancel, never on retrieve', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::response(['response_id' => 'resp_123', 'status' => 'in_progress', 'output' => []])]);

    expect(fn () => agentPerplexity()->agent()->retrieve('resp_123'))->toThrow(PrismException::class, 'invalid_response');
});

it('still accepts a cancel acknowledgement that names the run id', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::response(['id' => 'resp_123', 'status' => 'cancelling'])]);

    expect(agentPerplexity()->agent()->cancel('resp_123')->id)->toBe('resp_123');
});
