<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Domain\Security;

/**
 * One thing a human should review about an imported skill: a security pattern
 * matched in the skill body or a supporting code example. Advisory only — a
 * finding never disables a skill, it tells the reviewer what to look at.
 */
final readonly class SkillCheckFinding
{
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_DANGER = 'danger';

    public function __construct(
        /** Machine id of the matched rule, e.g. 'pipe_to_shell'. */
        public string $id,
        /** info | warning | danger */
        public string $severity,
        /** Short human category, e.g. 'Destructive command'. */
        public string $category,
        /** Where it matched: 'body' or a supporting file's relative path. */
        public string $location,
        /** The matched snippet, trimmed + truncated for display. */
        public string $evidence,
        /** What the reviewer should verify about this match. */
        public string $whatToCheck,
    ) {
    }

    /**
     * @return array{id: string, severity: string, category: string, location: string, evidence: string, whatToCheck: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'severity' => $this->severity,
            'category' => $this->category,
            'location' => $this->location,
            'evidence' => $this->evidence,
            'whatToCheck' => $this->whatToCheck,
        ];
    }
}


