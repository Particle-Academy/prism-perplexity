<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Perplexity\Agent\AgentClient;
use Prism\Perplexity\Agent\AgentProtocol;
use Prism\Perplexity\Agent\AgentStatus;
use Prism\Perplexity\Agent\AgentWaitTimedOut;
use Prism\Perplexity\Perplexity;
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

it('cancels through the documented endpoint', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::response([
        'id' => 'resp_123', 'status' => 'cancelled', 'model' => 'openai/gpt-5.4', 'output' => [],
    ])]);

    agentPerplexity()->agent()->cancel('resp_123');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/agent/resp_123/cancel'));
});
