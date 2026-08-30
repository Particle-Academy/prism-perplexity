<?php

declare(strict_types=1);

namespace Prism\Perplexity\Agent;

final readonly class AgentResponse
{
    /**
     * @param  list<array<string, mixed>>  $output
     * @param  list<array<string, mixed>>  $annotations
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public AgentProtocol $protocol,
        public AgentStatus $status,
        public ?string $model,
        public array $output,
        public array $annotations,
        public array $usage,
        public ?AgentError $error,
        public ?int $createdAt,
        public array $raw,
    ) {}

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isSuccessful(): bool
    {
        return $this->status === AgentStatus::Completed;
    }

    public function text(): string
    {
        $parts = [];
        foreach ($this->output as $item) {
            foreach ((array) data_get($item, 'content', []) as $content) {
                $text = data_get($content, 'text');
                if (is_string($text)) {
                    $parts[] = $text;
                }
            }
        }

        return implode("\n", $parts);
    }
}
