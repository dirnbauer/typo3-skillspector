<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Domain\Security;

use Webconsulting\Skillspector\Support\Typed;

/**
 * The result of running NVIDIA SkillSpector (github.com/NVIDIA/skillspector)
 * over one imported skill: the aggregated risk assessment (score 0-100,
 * severity, install recommendation) plus the individual issues mapped into
 * SkillCheckFinding objects so they render in the same review list as the
 * built-in scanner's findings.
 *
 * The scan is best-effort by design: when the binary is missing or the scan
 * fails, the report carries that as a status (UNAVAILABLE/ERROR) and the
 * built-in checks stand on their own. Only a successful scan can raise the
 * advisory review level. It never changes a skill's enabled or hidden state.
 */
final readonly class SkillspectorReport
{
    public const RECOMMENDATION_DO_NOT_INSTALL = 'DO_NOT_INSTALL';
    public const RECOMMENDATION_CAUTION = 'CAUTION';

    private const MAX_ISSUES = 40;
    private const TEXT_MAX = 200;

    /**
     * SkillSpector issue severity => review severity. Only CRITICAL maps to
     * DANGER; aggregate recommendations cap at WARNING.
     */
    private const SEVERITY_MAP = [
        'CRITICAL' => Severity::Danger,
        'HIGH' => Severity::Warning,
        'MEDIUM' => Severity::Warning,
        'LOW' => Severity::Info,
        'INFO' => Severity::Info,
    ];

    /**
     * Issue fields carrying the "what matched" text, most specific first.
     * `finding` is SkillSpector 2.x; `title` is the shape documented in the
     * project README.
     */
    private const EVIDENCE_FIELDS = ['finding', 'title', 'explanation', 'description'];

    /** Issue fields carrying the "what to do about it" text, most specific first. */
    private const REMEDIATION_FIELDS = ['remediation', 'explanation', 'recommendation', 'description'];

    /**
     * @param list<SkillCheckFinding> $findings
     */
    private function __construct(
        public ScanStatus $status,
        /** Risk score 0-100 (-1 when no successful scan). */
        public int $score,
        /** LOW | MEDIUM | HIGH | CRITICAL ('' when no successful scan). */
        public string $severity,
        /** SAFE | CAUTION | DO_NOT_INSTALL ('' when no successful scan). */
        public string $recommendation,
        /** SkillSpector version that produced the report. */
        public string $version,
        /** True when SkillSpector's LLM-assisted analysis actually ran (not just static). */
        public bool $llmUsed,
        /** Human note: error detail or install hint (empty on a successful scan). */
        public string $note,
        /** Issues mapped to review findings; merged into the main findings list, NOT serialized here. */
        public array $findings,
    ) {}

    public static function unavailable(string $note): self
    {
        return new self(ScanStatus::Unavailable, -1, '', '', '', false, $note, []);
    }

    public static function error(string $note): self
    {
        return new self(ScanStatus::Error, -1, '', '', '', false, $note, []);
    }

    /**
     * Parses the output of `skillspector scan --format json`. Tolerates log
     * noise before the JSON document; unparseable output becomes an
     * error-status report instead of an exception.
     */
    public static function fromScanOutput(string $output): self
    {
        $start = strpos($output, '{');
        $decoded = $start === false ? null : json_decode(substr($output, $start), true);
        if (!is_array($decoded)) {
            return self::error('SkillSpector returned no parseable JSON report.');
        }

        $risk = Typed::stringKeyedArray($decoded['risk_assessment'] ?? null);
        $metadata = Typed::stringKeyedArray($decoded['metadata'] ?? null);

        $rawIssues = is_array($decoded['issues'] ?? null) ? $decoded['issues'] : [];
        $issues = [];
        foreach ($rawIssues as $issue) {
            if (count($issues) >= self::MAX_ISSUES) {
                break;
            }
            if (is_array($issue)) {
                $issues[] = self::mapIssue(Typed::stringKeyedArray($issue));
            }
        }

        return new self(
            ScanStatus::Ok,
            is_numeric($risk['score'] ?? null) ? (int)$risk['score'] : -1,
            strtoupper(Typed::string($risk['severity'] ?? null)),
            strtoupper(Typed::string($risk['recommendation'] ?? null)),
            Typed::string($metadata['skillspector_version'] ?? null),
            (bool)($metadata['llm_requested'] ?? false) && (bool)($metadata['llm_available'] ?? false),
            '',
            $issues,
        );
    }

    /**
     * Aggregate recommendations cap at WARNING: ordinary documentation
     * patterns can accumulate into DO_NOT_INSTALL. Concrete danger findings
     * contribute separately through SkillCheckReport. Failed or unavailable
     * scans never raise the level.
     */
    public function levelFloor(): Severity
    {
        return match ($this->recommendation) {
            self::RECOMMENDATION_DO_NOT_INSTALL,
            self::RECOMMENDATION_CAUTION => Severity::Warning,
            default => Severity::None,
        };
    }

    /**
     * @return array{status: string, score: int, severity: string, recommendation: string, version: string, llmUsed: bool, note: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'score' => $this->score,
            'severity' => $this->severity,
            'recommendation' => $this->recommendation,
            'version' => $this->version,
            'llmUsed' => $this->llmUsed,
            'note' => $this->note,
        ];
    }

    /**
     * @param array<string, mixed> $issue
     */
    private static function mapIssue(array $issue): SkillCheckFinding
    {
        $location = Typed::stringKeyedArray($issue['location'] ?? null);
        $file = Typed::string($location['file'] ?? null);
        $line = Typed::int($location['start_line'] ?? null);
        $id = Typed::string($issue['id'] ?? null) ?: 'issue';

        $whatToCheck = self::firstText($issue, self::REMEDIATION_FIELDS)
            ?: 'Reported by NVIDIA SkillSpector — review the flagged location.';
        $confidence = $issue['confidence'] ?? null;
        if (is_numeric($confidence)) {
            $whatToCheck .= sprintf(' (confidence %d%%)', (int)round((float)$confidence * 100));
        }

        $category = Typed::string($issue['category'] ?? null) ?: 'SkillSpector finding';
        $pattern = Typed::string($issue['pattern'] ?? null);

        return new SkillCheckFinding(
            'skillspector:' . $id,
            self::SEVERITY_MAP[strtoupper(Typed::string($issue['severity'] ?? null))] ?? Severity::Info,
            $pattern === '' ? $category : $category . ': ' . $pattern,
            $file === '' ? 'skill' : $file . ($line > 0 ? ':' . $line : ''),
            self::crop(self::firstText($issue, self::EVIDENCE_FIELDS) ?: $id),
            self::crop($whatToCheck),
        );
    }

    /**
     * The first of $fields that holds non-empty text, or ''.
     *
     * @param array<string, mixed> $issue
     * @param list<string>         $fields
     */
    private static function firstText(array $issue, array $fields): string
    {
        foreach ($fields as $field) {
            $text = trim(Typed::string($issue[$field] ?? null));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private static function crop(string $text): string
    {
        return mb_strlen($text) > self::TEXT_MAX ? mb_substr($text, 0, self::TEXT_MAX) . '…' : $text;
    }
}
