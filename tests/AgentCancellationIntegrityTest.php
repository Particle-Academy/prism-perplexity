<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Prism\Perplexity\Agent\AgentClient;
use Prism\Prism\Exceptions\PrismException;

/**
 * @param  array<string, mixed>  $body
 * @return array{AgentClient, Factory}
 */
function cancellationIntegrityClient(array $body): array
{
    $http = new Factory;
    $http->preventStrayRequests();
    $http->fake(['*' => $http->response($body, 200)]);

    return [new AgentClient($http->baseUrl('https://api.perplexity.ai')), $http];
}

it('refuses agent-0021 present null response_id with a matching legacy id', function (): void {
    [$client, $http] = cancellationIntegrityClient(['response_id' => null, 'id' => 'resp_probe', 'status' => 'cancelling']);
    expect(fn () => $client->cancel('resp_probe'))->toThrow(PrismException::class, 'invalid_response');
    $http->assertSentCount(1);
});

it('refuses a mismatched cancellation acknowledgement without retrying', function (array $body): void {
    [$client, $http] = cancellationIntegrityClient($body);
    try {
        $client->cancel('resp_probe');
        $this->fail('A mismatched cancellation acknowledgement must be refused.');
    } catch (PrismException $error) {
        expect($error->getMessage())->toContain('invalid_response');
        expect($error->getMessage())->toContain('Cancellation state is uncertain. Use retrieve() with the requested ID to check its state.');
        expect($error->getMessage())->not->toContain('resp_probe');
        expect($error->getMessage())->not->toContain('resp_other');
    }
    $http->assertSentCount(1);
})->with([
    'documented response_id' => [['response_id' => 'resp_other', 'status' => 'cancelling']],
    'legacy id' => [['id' => 'resp_other', 'status' => 'cancelling']],
    'no fallback to matching legacy id' => [['response_id' => 'resp_other', 'id' => 'resp_probe', 'status' => 'cancelling']],
    'case-sensitive opaque id' => [['response_id' => 'resp_Probe', 'status' => 'cancelling']],
]);

it('accepts matching cancellation ids and retains the raw acknowledgement', function (array $body): void {
    [$client, $http] = cancellationIntegrityClient($body);
    $response = $client->cancel('resp_probe');
    expect($response->id)->toBe('resp_probe');
    expect($response->status->value)->toBe('cancelling');
    expect($response->isTerminal())->toBeFalse();
    expect($response->isSuccessful())->toBeFalse();
    expect($response->raw)->toBe($body);
    $http->assertSentCount(1);
})->with([
    'documented' => [['response_id' => 'resp_probe', 'status' => 'cancelling']],
    'legacy' => [['id' => 'resp_probe', 'status' => 'cancelling']],
    'response_id precedence' => [['response_id' => 'resp_probe', 'id' => 'resp_other', 'status' => 'cancelling']],
]);

it('keeps encoded cancellation paths and matches the original opaque id', function (): void {
    [$client, $http] = cancellationIntegrityClient(['id' => 'run/../admin', 'status' => 'cancelling']);
    expect($client->cancel('run/../admin')->id)->toBe('run/../admin');
    $http->assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.perplexity.ai/v1/agent/run%2F..%2Fadmin/cancel');
    $http->assertSentCount(1);
});

it('refuses other malformed present cancellation identifiers without legacy fallback', function (mixed $value): void {
    [$client, $http] = cancellationIntegrityClient(['response_id' => $value, 'id' => 'resp_probe', 'status' => 'cancelling']);
    expect(fn () => $client->cancel('resp_probe'))->toThrow(PrismException::class, 'invalid_response');
    $http->assertSentCount(1);
})->with(['empty' => [''], 'number' => [42], 'false' => [false], 'list' => [[]], 'object' => [new stdClass]]);
