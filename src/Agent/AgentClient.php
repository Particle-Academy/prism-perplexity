<?php

declare(strict_types=1);

namespace Prism\Perplexity\Agent;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;

final class AgentClient
{
    public function __construct(
        private readonly PendingRequest $client,
        private readonly ?Closure $sleeper = null,
    ) {}

    /**
     * @param  string|list<array<string, mixed>>  $input
     * @param  array<string, mixed>  $options
     */
    public function create(string|array $input, array $options = []): AgentResponse
    {
        return $this->map($this->client->post('/v1/agent', array_merge($options, [
            'input' => $input,
            'background' => $options['background'] ?? true,
        ])));
    }

    public function retrieve(string $id): AgentResponse
    {
        return $this->map($this->client->get('/v1/agent/'.rawurlencode($id)));
    }

    public function cancel(string $id): AgentResponse
    {
        return $this->map($this->client->post('/v1/agent/'.rawurlencode($id).'/cancel'));
    }

    public function wait(string $id, int $maxAttempts = 60, int $intervalMilliseconds = 1000): AgentResponse
    {
        if ($maxAttempts < 1 || $intervalMilliseconds < 0) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1 and intervalMilliseconds cannot be negative.');
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->retrieve($id);
            if ($response->isTerminal()) {
                return $response;
            }

            if ($attempt < $maxAttempts) {
                ($this->sleeper ?? static fn (int $milliseconds) => usleep($milliseconds * 1000))($intervalMilliseconds);
            }
        }

        throw new AgentWaitTimedOut($id, $maxAttempts);
    }

    private function map(Response $response): AgentResponse
    {
        $data = $response->json();
        if (! is_array($data)) {
            $this->fail($response, 'unreadable_response', 'The Agent API did not return a JSON object.');
        }

        if (! $response->successful()) {
            $this->fail(
                $response,
                is_string(data_get($data, 'error.type')) ? data_get($data, 'error.type') : 'agent_request_error',
                is_string(data_get($data, 'error.message')) ? data_get($data, 'error.message') : 'Unknown Agent API error.',
            );
        }

        $status = AgentStatus::tryFrom((string) data_get($data, 'status'));
        $id = data_get($data, 'id');
        if ($status === null || ! is_string($id) || $id === '') {
            $this->fail($response, 'invalid_response', 'The Agent API response is missing a recognized status or response id.');
        }

        $output = array_values(array_filter((array) data_get($data, 'output', []), 'is_array'));
        $annotations = [];
        foreach ($output as $item) {
            foreach ((array) data_get($item, 'content', []) as $content) {
                foreach ((array) data_get($content, 'annotations', []) as $annotation) {
                    if (is_array($annotation)) {
                        $annotations[] = $annotation;
                    }
                }
            }
        }

        $errorData = data_get($data, 'error');
        $error = is_array($errorData) && is_string($errorData['message'] ?? null)
            ? new AgentError($errorData['message'], self::nullableString($errorData['code'] ?? null), self::nullableString($errorData['type'] ?? null))
            : null;

        return new AgentResponse(
            id: $id,
            protocol: AgentProtocol::Agent,
            status: $status,
            model: self::nullableString(data_get($data, 'model')),
            output: $output,
            annotations: $annotations,
            usage: is_array(data_get($data, 'usage')) ? data_get($data, 'usage') : [],
            error: $error,
            createdAt: is_numeric(data_get($data, 'created_at')) ? (int) data_get($data, 'created_at') : null,
            raw: $data,
        );
    }

    private function fail(Response $response, string $type, string $message): never
    {
        throw PrismException::providerRequestErrorWithDetails('Perplexity', $response->status(), $type, $message);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
