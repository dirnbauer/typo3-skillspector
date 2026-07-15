<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillspector\Domain\ParsedSkill;
use Webconsulting\Skillspector\Domain\ScanSummary;
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
    ) {
    }

    public function scanAll(): ScanSummary
    {
        $levels = ['danger' => 0, 'warning' => 0, 'info' => 0];
        $messages = [];
        $rows = $this->findAll();
        foreach ($rows as $row) {
            $report = $this->check($row);
            $level = $report->level();
            if (isset($levels[$level])) {
                $levels[$level]++;
            }
            $this->persist(Typed::int($row['uid'] ?? 0), $report);
            foreach ($this->actionMessages($row, $report) as $message) {
                $messages[] = $message;
            }
        }
        return new ScanSummary(count($rows), $levels['danger'], $levels['warning'], $levels['info'], $messages);
    }

    /** @return list<array<string, mixed>> */
    public function findAll(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $rows = $queryBuilder->select('*')->from(self::TABLE)->orderBy('name')->executeQuery()->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[] = $row;
        }
        return $result;
    }

    /** @param array<string, mixed> $row */
    private function check(array $row): SkillCheckReport
    {
        $metadata = json_decode(Typed::string($row['raw_frontmatter'] ?? ''), true);
        if (!is_array($metadata) || array_is_list($metadata)) {
            $metadata = [];
        }
        $normalizedMetadata = [];
        foreach ($metadata as $key => $value) {
            $normalizedMetadata[(string)$key] = $value;
        }
        $allowed = json_decode(Typed::string($row['allowed_tools'] ?? ''), true);
        $allowedTools = is_array($allowed) ? implode(',', array_values(array_filter($allowed, 'is_string'))) : '';
        $skill = new ParsedSkill(
            Typed::string($row['identifier'] ?? ''),
            Typed::string($row['name'] ?? ''),
            Typed::string($row['description'] ?? ''),
            Typed::string($row['body'] ?? ''),
            $allowedTools,
            $normalizedMetadata,
        );
        // nr_llm does not ingest referenced assets/scripts; partial support is
        // explicit in nr_llm and the inspector never downloads or executes them.
        return $this->skillCheckService->check($skill, []);
    }

    private function persist(int $uid, SkillCheckReport $report): void
    {
        if ($uid <= 0) {
            return;
        }
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, [
            'tx_skillspector_check_level' => $report->level(),
            'tx_skillspector_check_report' => json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'tx_skillspector_checked_at' => $report->generatedAt,
            'tstamp' => time(),
        ], ['uid' => $uid]);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function actionMessages(array $row, SkillCheckReport $report): array
    {
        $name = Typed::string($row['name'] ?? $row['identifier'] ?? '') ?: '#' . Typed::int($row['uid'] ?? 0);
        $messages = [];
        if ($report->level() === 'danger') {
            $messages[] = sprintf('%s: danger finding(s). Review immediately and hide the skill manually if it is not trusted.', $name);
        } elseif ($report->level() === 'warning') {
            $messages[] = sprintf('%s: warning findings require review; no state was changed.', $name);
        }
        if ($report->hasCode && $report->license->isWarning()) {
            $messages[] = sprintf('%s: license %s requires human compatibility review before copying code into TYPO3.', $name, $report->license->declared ?: 'undeclared');
        }
        if ($report->skillspector !== null && $report->skillspector->status !== 'ok') {
            $messages[] = sprintf('%s: NVIDIA SkillSpector did not complete (%s). %s', $name, $report->skillspector->status, $report->skillspector->note);
        }
        return $messages;
    }
}

