<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Domain;

/**
 * A skill parsed from a SKILL.md file following the Anthropic skill structure:
 * YAML frontmatter with "name" and "description" (and optional keys like
 * "allowed-tools", "license", "metadata"), followed by a markdown body.
 */
final readonly class ParsedSkill
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $identifier,
        public string $name,
        public string $description,
        public string $body,
        public string $allowedTools,
        public array $metadata,
    ) {
    }

    public function contentHash(): string
    {
        return sha1($this->name . '|' . $this->description . '|' . $this->body . '|' . $this->allowedTools . '|' . json_encode($this->metadata));
    }
}


