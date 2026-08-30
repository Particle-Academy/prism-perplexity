<?php

declare(strict_types=1);

namespace Prism\Perplexity\Agent;

final readonly class AgentError
{
    public function __construct(
        public string $message,
        public ?string $code = null,
        public ?string $type = null,
    ) {}
}
