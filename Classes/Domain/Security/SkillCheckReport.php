<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Domain\Security;

/**
 * The full review report for one skill: security findings + the license
 * assessment + whether the skill ships code examples. Persisted as JSON on
 * tx_nrllm_skill.tx_skillspector_check_report and rendered in the inspector
 * module so a reviewer sees, per skill, exactly what to check. Purely advisory.
 *
 * The level and the per-severity counts are facts about the findings, so they
 * are derived once in the constructor rather than recomputed per reader.
 */
final readonly class SkillCheckReport
{
    /**
     * Highest advisory severity, for the list badge. Only a concrete danger
     * finding reaches DANGER; code-license and aggregate SkillSpector
     * recommendations can raise the level to WARNING. This never changes
     * skill state.
     */
    public Severity $level;

    /**
     * Finding counts per severity, so the review reads as evidence
     * (n danger / n warning / n info) rather than a single opaque verdict.
     *
     * @var array{danger: int, warning: int, info: int}
     */
    public array $severityCounts;

    /**
     * @param list<SkillCheckFinding> $findings
     */
    public function __construct(
        public array $findings,
        public LicenseAssessment $license,
        /** True when the imported body contains fenced code examples. */
        public bool $hasCode,
        public int $generatedAt,
        /** NVIDIA SkillSpector scan summary; null when the scan is disabled in the extension configuration. */
        public ?SkillspectorReport $skillspector = null,
    ) {
        $counts = ['danger' => 0, 'warning' => 0, 'info' => 0];
        $level = Severity::None;
        foreach ($findings as $finding) {
            $level = $level->max($finding->severity);
            match ($finding->severity) {
                Severity::Danger => $counts['danger']++,
                Severity::Warning => $counts['warning']++,
                Severity::Info => $counts['info']++,
                Severity::None => null,
            };
        }
        if ($hasCode && $license->isWarning()) {
            $level = $level->max(Severity::Warning);
        }

        $this->level = $level->max($skillspector?->levelFloor() ?? Severity::None);
        $this->severityCounts = $counts;
    }

    /**
     * @return array{generatedAt: int, hasCode: bool, level: string, severityCounts: array{danger: int, warning: int, info: int}, license: array<string, string>, findings: list<array<string, string>>, skillspector: array{status: string, score: int, severity: string, recommendation: string, version: string, llmUsed: bool, note: string}|null}
     */
    public function toArray(): array
    {
        return [
            'generatedAt' => $this->generatedAt,
            'hasCode' => $this->hasCode,
            'level' => $this->level->value,
            'severityCounts' => $this->severityCounts,
            'license' => $this->license->toArray(),
            'findings' => array_map(static fn(SkillCheckFinding $f): array => $f->toArray(), $this->findings),
            'skillspector' => $this->skillspector?->toArray(),
        ];
    }
}
