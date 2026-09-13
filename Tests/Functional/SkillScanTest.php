<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Skillspector\Domain\ParsedSkill;
use Webconsulting\Skillspector\Service\Security\SkillCheckService;
use Webconsulting\Skillspector\Service\SkillInspectionService;

/**
 * Runs the advisory checks the way the backend module and the scheduler
 * command run them: through the DI container, against SKILL.md fixtures.
 *
 * The NVIDIA SkillSpector subprocess is switched off here — its own
 * handling is unit-tested against a process fixture. What this suite
 * proves is that the built-in security and license checks, the container
 * wiring and the persistence of a report all hold together.
 */
final class SkillScanTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'scheduler',
    ];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'webconsulting/skillspector',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'skillspector' => [
                'skillspectorEnabled' => '0',
            ],
        ],
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function skillFiles(): iterable
    {
        yield 'instructions only, license declared' => ['tidy-pages', 'none'];
        yield 'pipes a remote script into a shell' => ['install-helper', 'danger'];
        yield 'ships code without a license' => ['snippet-library', 'warning'];
    }

    #[DataProvider('skillFiles')]
    #[Test]
    public function fixtureSkillFilesGetTheExpectedAdvisoryLevel(string $fixture, string $expectedLevel): void
    {
        $report = $this->get(SkillCheckService::class)->check(self::parseSkillFile($fixture));

        self::assertSame($expectedLevel, $report->level(), json_encode($report->toArray()) ?: '');
        self::assertNull($report->skillspector, 'The subprocess must not run when it is switched off.');
    }

    #[Test]
    public function theRiskySkillIsFlaggedWithAConcreteFinding(): void
    {
        $report = $this->get(SkillCheckService::class)->check(self::parseSkillFile('install-helper'));

        self::assertSame('danger', $report->level());
        self::assertNotSame([], $report->findings);
        self::assertContains('danger', array_map(
            static fn(object $finding): string => $finding->severity,
            $report->findings,
        ));
    }

    #[Test]
    public function scanningAllStoredSkillsPersistsOneReportPerSkill(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_skill');
        foreach (['tidy-pages', 'install-helper', 'snippet-library'] as $index => $fixture) {
            $skill = self::parseSkillFile($fixture);
            $connection->insert('tx_nrllm_skill', [
                'uid' => $index + 1,
                'pid' => 0,
                'identifier' => $fixture,
                'name' => $skill->name,
                'description' => $skill->description,
                'body' => $skill->body,
                'raw_frontmatter' => json_encode($skill->metadata, JSON_THROW_ON_ERROR),
                'allowed_tools' => json_encode(
                    array_map('trim', explode(',', $skill->allowedTools)),
                    JSON_THROW_ON_ERROR,
                ),
                'enabled' => 1,
            ]);
        }

        $summary = $this->get(SkillInspectionService::class)->scanAll();

        self::assertSame(3, $summary->checked);
        self::assertSame(1, $summary->danger);
        self::assertSame(1, $summary->warning);
        self::assertNotSame([], $summary->messages);

        $rows = $connection->executeQuery(
            'SELECT identifier, tx_skillspector_check_level, tx_skillspector_check_report, tx_skillspector_checked_at'
            . ' FROM tx_nrllm_skill ORDER BY identifier',
        )->fetchAllAssociative();

        $levels = array_column($rows, 'tx_skillspector_check_level', 'identifier');
        self::assertSame('danger', $levels['install-helper']);
        self::assertSame('warning', $levels['snippet-library']);
        self::assertSame('none', $levels['tidy-pages']);

        foreach ($rows as $row) {
            self::assertGreaterThan(0, (int)$row['tx_skillspector_checked_at']);
            $report = json_decode((string)$row['tx_skillspector_check_report'], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($report);
            self::assertArrayHasKey('findings', $report);
            self::assertArrayHasKey('license', $report);
        }
    }

    /**
     * Parses a SKILL.md fixture the way nr_llm stores it: YAML frontmatter
     * into the metadata array, everything after it as the body.
     */
    private static function parseSkillFile(string $fixture): ParsedSkill
    {
        $path = __DIR__ . '/Fixtures/Skills/' . $fixture . '/SKILL.md';
        $contents = file_get_contents($path);
        self::assertIsString($contents, 'Missing skill fixture ' . $path);

        $parts = preg_split('/^---\R/m', $contents, 3);
        self::assertIsArray($parts);
        self::assertCount(3, $parts, 'Skill fixture ' . $fixture . ' has no YAML frontmatter.');

        $metadata = Yaml::parse($parts[1]);
        self::assertIsArray($metadata);

        return new ParsedSkill(
            is_string($metadata['name'] ?? null) ? $metadata['name'] : $fixture,
            is_string($metadata['description'] ?? null) ? $metadata['description'] : '',
            trim($parts[2]),
            is_string($metadata['allowed-tools'] ?? null) ? $metadata['allowed-tools'] : '',
            $metadata,
        );
    }
}
