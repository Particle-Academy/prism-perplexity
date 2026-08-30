<?php

declare(strict_types=1);

namespace Prism\Perplexity\Agent;

use RuntimeException;

final class AgentWaitTimedOut extends RuntimeException
{
    public function __construct(public readonly string $responseId, public readonly int $attempts)
    {
        parent::__construct("Perplexity response [{$responseId}] was not terminal after {$attempts} retrieval attempts.");
    }
}
