<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Domain\Security;

/**
 * The full review report for one skill: security findings + the license
 * assessment + whether the skill ships code examples. Persisted as JSON on
 * tx_nrllm_skill.tx_skillspector_check_report and rendered in the inspector module so a
 * reviewer sees, per skill, exactly what to check. Purely advisory.
 */
final readonly class SkillCheckReport
{
    /** Severity ordering for "highest wins". */
    private const RANK = ['none' => 0, 'info' => 1, 'warning' => 2, 'danger' => 3];

    /**
     * @param list<SkillCheckFinding> $findings
     */
    public function __construct(
        public array $findings,
        public LicenseAssessment $license,
        /** True when the skill ships code (supporting code files or fenced code in the body). */
        public bool $hasCode,
        public int $generatedAt,
        /** NVIDIA SkillSpector scan summary; null when the scan is disabled in the extension configuration. */
        public ?SkillspectorReport $skillspector = null,
    ) {
    }

    /**
     * Highest severity across findings + the license warning + the SkillSpector
     * aggregate, for the list badge. A 'danger' result is what quarantines a
     * skill in a manual workflow, so only genuine danger reaches it:
     *
     *  - danger comes ONLY from a danger-SEVERITY finding (a located, concrete
     *    pattern: exposed secret, pipe-to-shell, exfiltration endpoint, or a
     *    CRITICAL SkillSpector issue);
     *  - the license warning caps at 'warning' (never a hard block) and only
     *    counts when the skill ships CODE — an odd license on instruction-only
     *    content has nothing to reuse;
     *  - the SkillSpector aggregate verdict (DO_NOT_INSTALL/CAUTION) caps at
     *    'warning' too — advisory context, never a quarantine trigger on its
     *    own (see SkillspectorReport::levelFloor).
     */
    public function level(): string
    {
        $level = 'none';
        foreach ($this->findings as $finding) {
            if ((self::RANK[$finding->severity] ?? 0) > (self::RANK[$level] ?? 0)) {
                $level = $finding->severity;
            }
        }
        if ($this->hasCode && $this->license->isWarning() && (self::RANK['warning'] > (self::RANK[$level] ?? 0))) {
            $level = 'warning';
        }
        $floor = $this->skillspector?->levelFloor() ?? 'none';
        if ((self::RANK[$floor] ?? 0) > (self::RANK[$level] ?? 0)) {
            $level = $floor;
        }
        return $level;
    }

    public function findingCount(): int
    {
        return count($this->findings);
    }

    /**
     * The danger-severity findings — the concrete evidence that justifies a
     * quarantine. Empty for a skill that is not quarantine-worthy, so the
     * module can state exactly WHY a skill was hidden (or that it was not).
     *
     * @return list<SkillCheckFinding>
     */
    public function dangerFindings(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (SkillCheckFinding $f): bool => $f->severity === SkillCheckFinding::SEVERITY_DANGER,
        ));
    }

    /**
     * Finding counts per severity, so the review reads as evidence
     * (n danger / n warning / n info) rather than a single opaque verdict.
     *
     * @return array{danger: int, warning: int, info: int}
     */
    public function severityCounts(): array
    {
        $counts = ['danger' => 0, 'warning' => 0, 'info' => 0];
        foreach ($this->findings as $finding) {
            if (isset($counts[$finding->severity])) {
                $counts[$finding->severity]++;
            }
        }
        return $counts;
    }

    /**
     * @return array{generatedAt: int, hasCode: bool, level: string, severityCounts: array{danger: int, warning: int, info: int}, license: array<string, string>, findings: list<array<string, string>>, skillspector: array{status: string, score: int, severity: string, recommendation: string, version: string, llmUsed: bool, note: string}|null}
     */
    public function toArray(): array
    {
        return [
            'generatedAt' => $this->generatedAt,
            'hasCode' => $this->hasCode,
            'level' => $this->level(),
            'severityCounts' => $this->severityCounts(),
            'license' => $this->license->toArray(),
            'findings' => array_map(static fn (SkillCheckFinding $f): array => $f->toArray(), $this->findings),
            'skillspector' => $this->skillspector?->toArray(),
        ];
    }
}

