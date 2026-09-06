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
 * fails, the report carries that as a status ('unavailable'/'error') and the
 * built-in checks stand on their own. Only a successful scan can raise the
 * advisory review level. It never changes a skill's enabled or hidden state.
 */
final readonly class SkillspectorReport
{
    public const STATUS_OK = 'ok';
    public const STATUS_UNAVAILABLE = 'unavailable';
    public const STATUS_ERROR = 'error';

    public const RECOMMENDATION_DO_NOT_INSTALL = 'DO_NOT_INSTALL';
    public const RECOMMENDATION_CAUTION = 'CAUTION';

    private const MAX_ISSUES = 40;
    private const TEXT_MAX = 200;

    /**
     * SkillSpector issue severity => review finding severity. Only CRITICAL
     * maps to 'danger'; aggregate recommendations cap at 'warning'.
     */
    private const SEVERITY_MAP = [
        'CRITICAL' => SkillCheckFinding::SEVERITY_DANGER,
        'HIGH' => SkillCheckFinding::SEVERITY_WARNING,
        'MEDIUM' => SkillCheckFinding::SEVERITY_WARNING,
        'LOW' => SkillCheckFinding::SEVERITY_INFO,
        'INFO' => SkillCheckFinding::SEVERITY_INFO,
    ];

    /**
     * @param list<SkillCheckFinding> $findings
     */
    private function __construct(
        /** ok | unavailable | error */
        public string $status,
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
    ) {
    }

    public static function unavailable(string $note): self
    {
        return new self(self::STATUS_UNAVAILABLE, -1, '', '', '', false, $note, []);
    }

    public static function error(string $note): self
    {
        return new self(self::STATUS_ERROR, -1, '', '', '', false, $note, []);
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

        $issues = [];
        $rawIssues = is_array($decoded['issues'] ?? null) ? $decoded['issues'] : [];
        foreach ($rawIssues as $issue) {
            if (count($issues) >= self::MAX_ISSUES) {
                break;
            }
            if (is_array($issue)) {
                $issues[] = self::mapIssue(Typed::stringKeyedArray($issue));
            }
        }

        return new self(
            self::STATUS_OK,
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
     * Aggregate recommendations cap at 'warning': ordinary documentation
     * patterns can accumulate into DO_NOT_INSTALL. Concrete danger findings
     * contribute separately through SkillCheckReport::level(). Failed or
     * unavailable scans never raise the level.
     */
    public function levelFloor(): string
    {
        return match ($this->recommendation) {
            self::RECOMMENDATION_DO_NOT_INSTALL,
            self::RECOMMENDATION_CAUTION => SkillCheckFinding::SEVERITY_WARNING,
            default => 'none',
        };
    }

    /**
     * @return array{status: string, score: int, severity: string, recommendation: string, version: string, llmUsed: bool, note: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'score' => $this->score,
            'severity' => $this->severity,
            'recommendation' => $this->recommendation,
            'version' => $this->version,
            'llmUsed' => $this->llmUsed,
            'note' => $this->note,
        ];
    }

    /**
     * Field names follow the report of SkillSpector 2.x (finding /
     * explanation / remediation / pattern); the names from the project
     * README (title / description / recommendation) are kept as fallbacks.
     *
     * @param array<string, mixed> $issue
     */
    private static function mapIssue(array $issue): SkillCheckFinding
    {
        $location = Typed::stringKeyedArray($issue['location'] ?? null);
        $file = Typed::string($location['file'] ?? null);
        $line = Typed::int($location['start_line'] ?? null);
        $where = $file === '' ? 'skill' : $file . ($line > 0 ? ':' . $line : '');

        $id = Typed::string($issue['id'] ?? null) ?: 'issue';
        $evidence = trim(
            Typed::string($issue['finding'] ?? null)
            ?: Typed::string($issue['title'] ?? null)
            ?: Typed::string($issue['explanation'] ?? null)
            ?: Typed::string($issue['description'] ?? null)
        ) ?: $id;

        $check = trim(
            Typed::string($issue['remediation'] ?? null)
            ?: Typed::string($issue['explanation'] ?? null)
            ?: Typed::string($issue['recommendation'] ?? null)
            ?: Typed::string($issue['description'] ?? null)
        );
        if ($check === '') {
            $check = 'Reported by NVIDIA SkillSpector — review the flagged location.';
        }
        $confidence = $issue['confidence'] ?? null;
        if (is_numeric($confidence)) {
            $check .= sprintf(' (confidence %d%%)', (int)round((float)$confidence * 100));
        }

        $category = Typed::string($issue['category'] ?? null) ?: 'SkillSpector finding';
        $pattern = Typed::string($issue['pattern'] ?? null);
        if ($pattern !== '') {
            $category .= ': ' . $pattern;
        }

        return new SkillCheckFinding(
            'skillspector:' . $id,
            self::SEVERITY_MAP[strtoupper(Typed::string($issue['severity'] ?? null))] ?? SkillCheckFinding::SEVERITY_INFO,
            $category,
            $where,
            self::crop($evidence),
            self::crop($check),
        );
    }

    private static function crop(string $text): string
    {
        return mb_strlen($text) > self::TEXT_MAX ? mb_substr($text, 0, self::TEXT_MAX) . '…' : $text;
    }
}

