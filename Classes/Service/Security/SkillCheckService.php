<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Service\Security;

use Webconsulting\Skillspector\Domain\ParsedSkill;
use Webconsulting\Skillspector\Domain\Security\SkillCheckReport;
use Webconsulting\Skillspector\Domain\Security\SkillspectorReport;
use Webconsulting\Skillspector\Support\Typed;

/**
 * Runs the mandatory review checks on one imported skill: the built-in
 * security scan of the body + code examples, a license-compatibility
 * assessment against TYPO3's GPL-2.0-or-later, and — when the binary is
 * installed — an NVIDIA SkillSpector scan. Produces a SkillCheckReport the
 * inspector persists and the backend module renders. Never disables or hides
 * a skill: every state change remains an explicit administrator action.
 */
final class SkillCheckService
{
    public function __construct(
        private readonly SkillSecurityScanner $securityScanner,
        private readonly LicenseChecker $licenseChecker,
        private readonly SkillspectorScanner $skillspectorScanner,
    ) {
    }

    /**
     * @param array<string, string> $files relative path => content (SKILL.md excluded)
     */
    public function check(ParsedSkill $skill, array $files): SkillCheckReport
    {
        $hasCode = CodeDetection::skillHasCode($skill->body, $files);
        $findings = $this->securityScanner->scan($skill->body, $files);
        $license = $this->licenseChecker->assess($this->extractLicense($skill->metadata), $hasCode);

        $skillspector = $this->skillspectorScanner->scan($skill, $files);
        if ($skillspector !== null) {
            $findings = [...$findings, ...$skillspector->findings];
        }

        return new SkillCheckReport($findings, $license, $hasCode, time(), $skillspector);
    }

    /**
     * Whether a stored check_report needs to be regenerated even though the
     * skill content is unchanged: it is empty (skill imported before the
     * checks existed), it predates the SkillSpector integration, or its
     * SkillSpector scan was skipped because the binary was missing and the
     * binary is available NOW (so installing SkillSpector upgrades existing
     * reports on the next sync, without churning reports while it stays
     * missing).
     */
    public function isReportStale(string $checkReportJson): bool
    {
        if (trim($checkReportJson) === '') {
            return true;
        }
        if (!$this->skillspectorScanner->isEnabled()) {
            return false;
        }
        $report = json_decode($checkReportJson, true);
        if (!is_array($report)) {
            return true;
        }
        $skillspector = $report['skillspector'] ?? null;
        if (!is_array($skillspector)) {
            return true;
        }
        return Typed::string($skillspector['status'] ?? null) === SkillspectorReport::STATUS_UNAVAILABLE
            && $this->skillspectorScanner->isAvailable();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function extractLicense(array $metadata): ?string
    {
        foreach (['license', 'License', 'licence', 'spdx', 'SPDX-License-Identifier'] as $key) {
            if (isset($metadata[$key])) {
                $value = $metadata[$key];
                if (is_array($value)) {
                    $value = reset($value);
                }
                $string = Typed::string($value);
                if ($string !== '') {
                    return $string;
                }
            }
        }
        return null;
    }
}

