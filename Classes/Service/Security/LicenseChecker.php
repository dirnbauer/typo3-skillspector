<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Service\Security;

use Webconsulting\Skillspector\Domain\Security\LicenseAssessment;

/**
 * Compares a skill's declared license against TYPO3's own license,
 * GPL-2.0-or-later. The question only bites when the skill ships CODE examples
 * that someone might copy into a GPL-2.0-or-later codebase.
 *
 * Output is advisory: an incompatible/unknown license yields a WARNING with
 * "what to check", never a block. License law is nuanced (Apache-2.0 and
 * GPL-3.0-only are usable only via the "or-later"/v3 upgrade path), so we
 * classify conservatively and always defer the final call to a human.
 */
final class LicenseChecker
{
    private const TYPO3_LICENSE = 'GPL-2.0-or-later';

    /**
     * Permissive or GPL-2-compatible: safe to reuse under GPL-2.0-or-later.
     *
     * @var array<string, string> normalized-key => display label
     */
    private const COMPATIBLE = [
        'mit' => 'MIT', 'x11' => 'X11', 'isc' => 'ISC',
        'bsd-2-clause' => 'BSD-2-Clause', 'bsd-3-clause' => 'BSD-3-Clause', 'bsd' => 'BSD',
        '0bsd' => '0BSD', 'zlib' => 'Zlib', 'libpng' => 'libpng',
        'unlicense' => 'Unlicense', 'cc0-1.0' => 'CC0-1.0', 'wtfpl' => 'WTFPL',
        'public-domain' => 'Public Domain', 'boost-1.0' => 'BSL-1.0', 'bsl-1.0' => 'BSL-1.0',
        'psf' => 'Python-2.0', 'python-2.0' => 'Python-2.0',
        'gpl-2.0' => 'GPL-2.0-only', 'gpl-2.0-only' => 'GPL-2.0-only',
        'gpl-2.0-or-later' => 'GPL-2.0-or-later', 'gpl-2.0+' => 'GPL-2.0-or-later',
        'gpl-3.0-or-later' => 'GPL-3.0-or-later', 'gpl-3.0+' => 'GPL-3.0-or-later',
        'lgpl-2.1' => 'LGPL-2.1', 'lgpl-2.1-or-later' => 'LGPL-2.1-or-later',
        'agpl-3.0-or-later' => 'AGPL-3.0-or-later',
    ];

    /**
     * Usable only under conditions — needs a human OK before redistribution.
     *
     * @var array<string, array{label: string, why: string}>
     */
    private const REVIEW = [
        'apache-2.0' => ['label' => 'Apache-2.0', 'why' => 'Apache-2.0 is incompatible with GPL-2.0-only; it is only compatible via the "or-later" upgrade to GPLv3.'],
        'apache-2' => ['label' => 'Apache-2.0', 'why' => 'Apache-2.0 is incompatible with GPL-2.0-only; it is only compatible via the "or-later" upgrade to GPLv3.'],
        'mpl-2.0' => ['label' => 'MPL-2.0', 'why' => 'MPL-2.0 is compatible per-file, but mixing into a GPL work has conditions.'],
        'gpl-3.0' => ['label' => 'GPL-3.0-only', 'why' => 'GPL-3.0-only forces the combined work to GPLv3; fine under the "or-later" path, but it changes the effective license.'],
        'gpl-3.0-only' => ['label' => 'GPL-3.0-only', 'why' => 'GPL-3.0-only forces the combined work to GPLv3; fine under the "or-later" path, but it changes the effective license.'],
        'lgpl-3.0' => ['label' => 'LGPL-3.0', 'why' => 'LGPL-3.0 combines with GPLv3, i.e. only via the "or-later" path.'],
        'cc-by-4.0' => ['label' => 'CC-BY-4.0', 'why' => 'CC-BY-4.0 is one-way compatible with GPLv3 only; not intended for source code.'],
        'cc-by-sa-4.0' => ['label' => 'CC-BY-SA-4.0', 'why' => 'CC-BY-SA-4.0 is one-way compatible with GPLv3 only; not intended for source code.'],
        'epl-2.0' => ['label' => 'EPL-2.0', 'why' => 'EPL is generally considered GPL-incompatible; review carefully.'],
        'eupl-1.2' => ['label' => 'EUPL-1.2', 'why' => 'EUPL compatibility with GPL depends on the version; review carefully.'],
    ];

    /**
     * Non-free or clearly GPL-incompatible.
     *
     * @var array<string, string>
     */
    private const INCOMPATIBLE = [
        'cc-by-nc' => 'CC-BY-NC (non-commercial)', 'cc-by-nc-4.0' => 'CC-BY-NC-4.0 (non-commercial)',
        'cc-by-nd' => 'CC-BY-ND (no derivatives)', 'cc-by-nc-nd-4.0' => 'CC-BY-NC-ND-4.0 (non-free)',
        'cc-by-nc-sa-4.0' => 'CC-BY-NC-SA-4.0 (non-commercial)',
        'proprietary' => 'Proprietary', 'commercial' => 'Commercial',
        'all-rights-reserved' => 'All rights reserved', 'none' => 'No reuse granted',
        'ms-pl' => 'MS-PL', 'cddl-1.0' => 'CDDL-1.0', 'cpol' => 'CPOL',
    ];

    public function assess(?string $declared, bool $hasCode): LicenseAssessment
    {
        $raw = trim((string)$declared);
        $key = $this->normalizeKey($raw);

        if ($raw === '' || $key === '' || in_array($key, ['unknown', 'unlicensed', 'tbd', 'n/a', 'na'], true)) {
            return new LicenseAssessment(
                $raw,
                'unknown',
                LicenseAssessment::STATUS_UNKNOWN,
                'No license declared.',
                $hasCode
                    ? 'This skill ships code examples with no declared license. Reuse/redistribution terms are unverified — confirm the source permits use under ' . self::TYPO3_LICENSE . ' before copying its code.'
                    : '',
            );
        }

        if (isset(self::COMPATIBLE[$key])) {
            $label = self::COMPATIBLE[$key];
            return new LicenseAssessment(
                $raw,
                $label,
                LicenseAssessment::STATUS_COMPATIBLE,
                $label . ' is compatible with ' . self::TYPO3_LICENSE . '.',
                '',
            );
        }

        if (isset(self::REVIEW[$key])) {
            $entry = self::REVIEW[$key];
            return new LicenseAssessment(
                $raw,
                $entry['label'],
                LicenseAssessment::STATUS_REVIEW,
                $entry['why'],
                $hasCode
                    ? 'Before reusing this skill\'s code in TYPO3 (' . self::TYPO3_LICENSE . '), confirm the ' . $entry['label'] . ' terms are met — often only via the GPLv3 "or-later" path.'
                    : 'Instruction-only skill; the ' . $entry['label'] . ' terms matter only if you copy code from it.',
            );
        }

        if (isset(self::INCOMPATIBLE[$key])) {
            $label = self::INCOMPATIBLE[$key];
            return new LicenseAssessment(
                $raw,
                $label,
                LicenseAssessment::STATUS_INCOMPATIBLE,
                $label . ' is not freely compatible with ' . self::TYPO3_LICENSE . '.',
                $hasCode
                    ? 'This skill\'s code is under ' . $label . ' and likely cannot be redistributed under ' . self::TYPO3_LICENSE . '. Do NOT copy its code into TYPO3 without clearing the license.'
                    : 'Instruction text is under ' . $label . '; do not copy any code or substantial text from it into a GPL project.',
            );
        }

        // Recognised as a license string but not in our tables — surface for review.
        return new LicenseAssessment(
            $raw,
            $raw,
            LicenseAssessment::STATUS_REVIEW,
            'Unrecognised license "' . $raw . '".',
            'Verify whether "' . $raw . '" is compatible with ' . self::TYPO3_LICENSE . ' before reusing this skill\'s content.',
        );
    }

    private function normalizeKey(string $raw): string
    {
        $key = strtolower(trim($raw));
        $key = str_replace([' ', '_'], '-', $key);
        // Drop common noise: "license", "licensed under", version "v" prefixes.
        $key = (string)preg_replace('~\blicen[sc]e[d]?\b|\bunder\b|\bthe\b~', '', $key);
        $key = trim((string)preg_replace('~-{2,}~', '-', $key), '- ');
        $key = (string)preg_replace('~\bv(\d)~', '$1', $key);
        return $key;
    }
}


