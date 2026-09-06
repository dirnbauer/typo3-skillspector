<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Service\Security;

use Webconsulting\Skillspector\Domain\ParsedSkill;
use Webconsulting\Skillspector\Domain\Security\SkillCheckReport;
use Webconsulting\Skillspector\Support\Typed;

/**
 * Combines advisory security, code-license and optional NVIDIA SkillSpector
 * checks. The inspector persists the report without changing skill state.
 */
final class SkillCheckService
{
    public function __construct(
        private readonly SkillSecurityScanner $securityScanner,
        private readonly LicenseChecker $licenseChecker,
        private readonly SkillspectorScanner $skillspectorScanner,
    ) {
    }

    public function check(ParsedSkill $skill): SkillCheckReport
    {
        // nr_llm imports the instruction body, including fenced code examples.
        $hasCode = preg_match(
            '~```[ \t]*(php|phtml|js|javascript|ts|typescript|jsx|tsx|py|python|rb|ruby|go|golang|rust|rs|java|kotlin|kt|c|cpp|cs|csharp|sh|bash|shell|zsh|sql|pl|perl|lua|swift|scala|groovy|dart)\b~i',
            $skill->body,
        ) === 1;
        $findings = $this->securityScanner->scan($skill->body);
        $license = $this->licenseChecker->assess($this->extractLicense($skill->metadata), $hasCode);

        $skillspector = $this->skillspectorScanner->scan($skill);
        if ($skillspector !== null) {
            $findings = [...$findings, ...$skillspector->findings];
        }

        return new SkillCheckReport($findings, $license, $hasCode, time(), $skillspector);
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
