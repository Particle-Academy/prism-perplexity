<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Prism\Perplexity\Agent\AgentClient;
use Prism\Prism\Exceptions\PrismException;

/**
 * The cross-language agent-response corpus from `prism-parity`.
 *
 * This package is the REFERENCE, so this file proves the corpus has not
 * drifted from the code it was generated against — which is what makes the
 * ports' "I differ from the reference HERE and nowhere else" assertions mean
 * anything. Without it they would be pinned to a snapshot of PHP that PHP had
 * moved on from, and every one of them would stay green while the claim
 * quietly stopped being true.
 *
 * The response body is UNTRUSTED input — whatever the provider sent — and
 * three things a consumer cannot decide for itself ride on how it is read:
 * whether the run is finished, which citations it carries, and whether the
 * body is refused at all.
 */

/** @return array<int, array<string, mixed>> */
function agentResponseCorpus(): array
{
    /** @var array{cases: array<int, array<string, mixed>>} $document */
    $document = json_decode(
        (string) file_get_contents(__DIR__.'/fixtures/perplexity-agent-response.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $document['cases'];
}

/**
 * `retrieve` rather than `create`, deliberately: it is the call the polling
 * loop makes, it sends no body of its own, and so each row records the PARSE
 * and nothing about the request that provoked it.
 *
 * @param  array<string, mixed>  $case
 * @return array<string, mixed>
 */
function parseAgentResponse(array $case): array
{
    $factory = new Factory;
    $factory->fake(['*' => $factory->response($case['body'], $case['http_status'])]);

    try {
        $response = (new AgentClient($factory->baseUrl('https://api.perplexity.ai')))->retrieve('resp_probe');
    } catch (PrismException $exception) {
        return [
            'refused' => true,
            // Null because this package HAS no machine-readable code — it
            // builds a message string. Both ports define one. That asymmetry is
            // G-30 and is recorded rather than papered over.
            'error_code' => null,
            'error_type' => preg_match('/\]:\s*([a-z_]+)\s+-/', $exception->getMessage(), $m) === 1
                ? $m[1]
                : $exception->getMessage(),
        ];
    }

    return [
        'refused' => false,
        'id' => $response->id,
        'status' => $response->status->value,
        'terminal' => $response->isTerminal(),
        'successful' => $response->isSuccessful(),
        'model' => $response->model,
        'created_at' => $response->createdAt,
        'output_count' => count($response->output),
        'annotations' => $response->annotations,
        'usage' => $response->usage,
        'error' => $response->error === null ? null : [
            'message' => $response->error->message,
            'code' => $response->error->code,
            'type' => $response->error->type,
        ],
        'text' => $response->text(),
    ];
}

it('is the whole suite, not a subset someone trimmed to green', function (): void {
    expect(agentResponseCorpus())->toHaveCount(17);
});

it('still parses each body the way the corpus recorded', function (array $case): void {
    expect(parseAgentResponse($case))->toBe($case['parsed']['php']);
})->with(fn (): array => collect(agentResponseCorpus())
    ->mapWithKeys(fn (array $case): array => [$case['id'].' — '.$case['title'] => [$case]])
    ->all());

it('agrees with BOTH ports on whether a run is finished, on every row', function (): void {
    // The load-bearing value in the whole file, and the good news: the status
    // vocabulary and the terminal classification agree in all three languages.
    // The polling loop is the thing that reads it, and it has exactly two ways
    // to be wrong — return an unfinished run as the answer, or poll a finished
    // one to timeout. Asserted across the corpus rather than trusted, because
    // this is the row that would be cheapest to break by accident.
    $disagreements = collect(agentResponseCorpus())
        ->filter(fn (array $case): bool => $case['parsed']['php']['refused'] === false)
        ->filter(fn (array $case): bool => $case['parsed']['php']['terminal'] !== $case['parsed']['ts']['terminal']
            || $case['parsed']['php']['terminal'] !== $case['parsed']['py']['terminal'])
        ->pluck('id')
        ->all();

    expect($disagreements)->toBe([]);
});

it('agrees with BOTH ports on the citations and their ORDER, on every row', function (): void {
    // A UI numbers citations and the answer text refers to them by that number,
    // so a different flattening renumbers every citation in the answer. Holds
    // across the malformed rows too — a bare string in an annotation list is
    // dropped by all three, and the sibling after it still arrives.
    $disagreements = collect(agentResponseCorpus())
        ->filter(fn (array $case): bool => $case['parsed']['php']['refused'] === false)
        ->filter(fn (array $case): bool => $case['parsed']['php']['annotations'] !== $case['parsed']['ts']['annotations']
            || $case['parsed']['php']['annotations'] !== $case['parsed']['py']['annotations'])
        ->pluck('id')
        ->all();

    expect($disagreements)->toBe([]);
});

it('renders an absent usage as an empty LIST, where both ports render a map', function (): void {
    // G-29, and a second instance of G-20: an empty PHP array encodes as `[]`,
    // never `{}`. A consumer reading `usage.input_tokens` off serialised output
    // gets a list where it expected an object, and the shape depends on which
    // language served the request. Pinned in the negative.
    //
    // The ports' rows are read back as OBJECTS rather than through the
    // associative decode the rest of this file uses, and that detail is the
    // whole point: `json_decode($json, true)` turns `{}` into `[]`, so PHP
    // cannot see this defect using the tool the defect is in. Asserting the
    // ports' shape from an associative decode would compare `[]` with `[]` and
    // pass while the divergence was live -- the same trap `guard-corpus.mjs`
    // documents for 2^53 integers, arriving in a different file.
    $case = collect(agentResponseCorpus())->firstWhere('id', 'agent-0002');

    $asObjects = json_decode(
        (string) file_get_contents(__DIR__.'/fixtures/perplexity-agent-response.json'),
        false,
        512,
        JSON_THROW_ON_ERROR,
    );

    $ports = collect($asObjects->cases)->firstWhere('id', 'agent-0002')->parsed;

    expect(parseAgentResponse($case)['usage'])->toBe([])
        ->and(json_encode(parseAgentResponse($case)['usage']))->toBe('[]')
        ->and($ports->php->usage)->toBeArray()
        ->and($ports->ts->usage)->toBeObject()
        ->and($ports->py->usage)->toBeObject();
});

it('reports a JSON ARRAY body as a missing status rather than an unreadable body', function (): void {
    // G-31, and the reference is on the wrong side of this one. `is_array()` is
    // true for a decoded JSON LIST as well as a decoded JSON object, so a body
    // that is not a map at all passes the readability check and then fails the
    // status check. The caller is told the provider sent a response missing a
    // status, when the provider sent something that was never a response —
    // a proxy, a captive portal, a misrouted endpoint.
    //
    // Both ports say `unreadable_response`, which is what happened.
    $case = collect(agentResponseCorpus())->firstWhere('id', 'agent-0012');

    expect(parseAgentResponse($case)['error_type'])->toBe('invalid_response')
        ->and($case['parsed']['ts']['error_code'])->toBe('unreadable_response')
        ->and($case['parsed']['py']['error_code'])->toBe('unreadable_response');
});

it('COERCES a numeric-string created_at, where both ports null it', function (): void {
    // G-32. JSON has one number type and providers still send timestamps as
    // strings. Neither answer is obviously right — this one is forgiving, the
    // ports are predictable — but a consumer cannot have both, and a timestamp
    // that exists in one language and is null in another is the kind of thing
    // a caller only discovers by rendering a blank date.
    $case = collect(agentResponseCorpus())->firstWhere('id', 'agent-0016');

    expect(parseAgentResponse($case)['created_at'])->toBe(1730000000)
        ->and($case['parsed']['ts']['created_at'])->toBeNull()
        ->and($case['parsed']['py']['created_at'])->toBeNull();
});
