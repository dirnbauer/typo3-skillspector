<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillspector\Domain\ParsedSkill;
use Webconsulting\Skillspector\Domain\ScanSummary;
use Webconsulting\Skillspector\Domain\Security\ScanStatus;
use Webconsulting\Skillspector\Domain\Security\Severity;
use Webconsulting\Skillspector\Domain\Security\SkillCheckReport;
use Webconsulting\Skillspector\Service\Security\SkillCheckService;
use Webconsulting\Skillspector\Support\Typed;

/** Runs advisory checks against nr_llm-owned skills and persists only reports. */
final class SkillInspectionService
{
    private const TABLE = 'tx_nrllm_skill';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SkillCheckService $skillCheckService,
    ) {}

    public function scanAll(): ScanSummary
    {
        $counts = ['danger' => 0, 'warning' => 0, 'info' => 0];
        $messages = [];
        $rows = $this->findAll();
        foreach ($rows as $row) {
            $report = $this->skillCheckService->check(self::toParsedSkill($row));
            match ($report->level) {
                Severity::Danger => $counts['danger']++,
                Severity::Warning => $counts['warning']++,
                Severity::Info => $counts['info']++,
                Severity::None => null,
            };
            $this->persist(Typed::int($row['uid'] ?? 0), $report);
            foreach (self::actionMessages($row, $report) as $message) {
                $messages[] = $message;
            }
        }

        return new ScanSummary(count($rows), $counts['danger'], $counts['warning'], $counts['info'], $messages);
    }

    /** @return list<array<string, mixed>> */
    public function findAll(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return array_values($queryBuilder->select('*')->from(self::TABLE)->orderBy('name')->executeQuery()->fetchAllAssociative());
    }

    /**
     * nr_llm does not ingest referenced assets or scripts; that partial
     * support is explicit in nr_llm, and the inspector never downloads or
     * executes them either — so a stored row is the whole skill.
     *
     * @param array<string, mixed> $row
     */
    private static function toParsedSkill(array $row): ParsedSkill
    {
        $metadata = Typed::stringKeyedArray(json_decode(Typed::string($row['raw_frontmatter'] ?? ''), true));
        if (array_is_list($metadata)) {
            $metadata = [];
        }
        $allowed = json_decode(Typed::string($row['allowed_tools'] ?? ''), true);

        return new ParsedSkill(
            Typed::string($row['name'] ?? ''),
            Typed::string($row['description'] ?? ''),
            Typed::string($row['body'] ?? ''),
            is_array($allowed) ? implode(',', array_values(array_filter($allowed, 'is_string'))) : '',
            $metadata,
        );
    }

    private function persist(int $uid, SkillCheckReport $report): void
    {
        if ($uid <= 0) {
            return;
        }
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, [
            'tx_skillspector_check_level' => $report->level->value,
            'tx_skillspector_check_report' => json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'tx_skillspector_checked_at' => $report->generatedAt,
            'tstamp' => time(),
        ], ['uid' => $uid]);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function actionMessages(array $row, SkillCheckReport $report): array
    {
        $name = Typed::string($row['name'] ?? $row['identifier'] ?? '') ?: '#' . Typed::int($row['uid'] ?? 0);

        $messages = match ($report->level) {
            Severity::Danger => [sprintf('%s: danger finding(s). Review immediately and hide the skill manually if it is not trusted.', $name)],
            Severity::Warning => [sprintf('%s: warning findings require review; no state was changed.', $name)],
            Severity::Info, Severity::None => [],
        };
        if ($report->hasCode && $report->license->isWarning()) {
            $messages[] = sprintf('%s: license %s requires human compatibility review before copying code into TYPO3.', $name, $report->license->declared ?: 'undeclared');
        }
        if ($report->skillspector !== null && $report->skillspector->status !== ScanStatus::Ok) {
            $messages[] = sprintf('%s: NVIDIA SkillSpector did not complete (%s). %s', $name, $report->skillspector->status->value, $report->skillspector->note);
        }

        return $messages;
    }
}
