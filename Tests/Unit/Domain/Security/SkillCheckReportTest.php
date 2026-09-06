<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Tests\Unit\Domain\Security;

use PHPUnit\Framework\TestCase;
use Webconsulting\Skillspector\Domain\Security\LicenseAssessment;
use Webconsulting\Skillspector\Domain\Security\SkillCheckFinding;
use Webconsulting\Skillspector\Domain\Security\SkillCheckReport;
use Webconsulting\Skillspector\Domain\Security\SkillspectorReport;

final class SkillCheckReportTest extends TestCase
{
    private static function compatibleLicense(): LicenseAssessment
    {
        return new LicenseAssessment('MIT', 'MIT', LicenseAssessment::STATUS_COMPATIBLE, 'ok', '');
    }

    private static function finding(string $severity): SkillCheckFinding
    {
        return new SkillCheckFinding('rule', $severity, 'Category', 'body', 'L1: x', 'check it');
    }

    private static function spector(string $recommendation): SkillspectorReport
    {
        $json = (string)json_encode([
            'risk_assessment' => ['score' => 80, 'severity' => 'HIGH', 'recommendation' => $recommendation],
            'metadata' => ['skillspector_version' => '1.0.0'],
        ]);
        return SkillspectorReport::fromScanOutput($json);
    }

    public function testLevelIsNoneWithoutFindingsAndWithoutSkillspector(): void
    {
        $report = new SkillCheckReport([], self::compatibleLicense(), false, 1_000);

        self::assertSame('none', $report->level());
    }

    public function testDoNotInstallWithoutADangerFindingIsWarningNotDanger(): void
    {
        // An aggregate recommendation must not be presented as concrete danger.
        $report = new SkillCheckReport(
            [self::finding('warning'), self::finding('warning')],
            self::compatibleLicense(),
            false,
            1_000,
            self::spector('DO_NOT_INSTALL'),
        );

        self::assertSame('warning', $report->level());
    }

    public function testDangerSeverityFindingReachesDangerAndKeepsItsEvidence(): void
    {
        $danger = self::finding('danger');
        $report = new SkillCheckReport(
            [self::finding('warning'), $danger],
            self::compatibleLicense(),
            false,
            1_000,
            self::spector('DO_NOT_INSTALL'),
        );

        self::assertSame('danger', $report->level());
        self::assertContains($danger->toArray(), $report->toArray()['findings']);
    }

    public function testCautionVerdictRaisesTheLevelToWarning(): void
    {
        $report = new SkillCheckReport([], self::compatibleLicense(), false, 1_000, self::spector('CAUTION'));

        self::assertSame('warning', $report->level());
    }

    public function testSafeVerdictNeverLowersADangerFinding(): void
    {
        $report = new SkillCheckReport([self::finding('danger')], self::compatibleLicense(), false, 1_000, self::spector('SAFE'));

        self::assertSame('danger', $report->level());
    }

    public function testFailedScanNeverRaisesTheLevel(): void
    {
        $report = new SkillCheckReport([], self::compatibleLicense(), false, 1_000, SkillspectorReport::error('boom'));

        self::assertSame('none', $report->level());
    }

    public function testSeverityCountsTallyEachSeverity(): void
    {
        $report = new SkillCheckReport(
            [self::finding('danger'), self::finding('warning'), self::finding('warning'), self::finding('info')],
            self::compatibleLicense(),
            false,
            1_000,
        );

        self::assertSame(['danger' => 1, 'warning' => 2, 'info' => 1], $report->severityCounts());
    }

    public function testToArrayCarriesSeverityCounts(): void
    {
        $report = new SkillCheckReport(
            [self::finding('danger'), self::finding('info')],
            self::compatibleLicense(),
            false,
            1_000,
        );

        self::assertSame(['danger' => 1, 'warning' => 0, 'info' => 1], $report->toArray()['severityCounts']);
    }

    public function testToArrayIncludesTheSkillspectorSummary(): void
    {
        $report = new SkillCheckReport([], self::compatibleLicense(), false, 1_000, self::spector('CAUTION'));

        $array = $report->toArray();
        self::assertIsArray($array['skillspector']);
        self::assertSame('CAUTION', $array['skillspector']['recommendation']);
        self::assertSame('warning', $array['level']);
    }

    public function testToArrayCarriesNullWhenTheScanIsDisabled(): void
    {
        $report = new SkillCheckReport([], self::compatibleLicense(), false, 1_000);

        self::assertNull($report->toArray()['skillspector']);
    }
}

