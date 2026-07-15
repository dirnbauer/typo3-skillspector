<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Domain\Security;

/**
 * The result of comparing a skill's declared license against TYPO3's license
 * (GPL-2.0-or-later). Advisory only: an incompatible or unknown license
 * produces a WARNING the reviewer must judge — it never disables the skill.
 */
final readonly class LicenseAssessment
{
    /** Clearly usable in a GPL-2.0-or-later project (permissive or GPL-2-compatible). */
    public const STATUS_COMPATIBLE = 'compatible';
    /** Usable only under conditions (e.g. the "or-later"/v3 path); review before redistributing. */
    public const STATUS_REVIEW = 'review';
    /** Non-free or GPL-incompatible; redistribution under GPL-2.0-or-later is doubtful. */
    public const STATUS_INCOMPATIBLE = 'incompatible';
    /** No license declared. */
    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        /** The raw value from the skill frontmatter, e.g. 'Apache-2.0' or '' . */
        public string $declared,
        /** Normalised label, e.g. 'Apache-2.0', 'GPL-2.0-or-later', 'unknown'. */
        public string $normalized,
        /** compatible | review | incompatible | unknown */
        public string $status,
        /** One-line explanation of the status. */
        public string $message,
        /** What the reviewer should verify (empty when nothing to do). */
        public string $whatToCheck,
    ) {
    }

    public function isWarning(): bool
    {
        return $this->status !== self::STATUS_COMPATIBLE;
    }

    /**
     * @return array{declared: string, normalized: string, status: string, message: string, whatToCheck: string}
     */
    public function toArray(): array
    {
        return [
            'declared' => $this->declared,
            'normalized' => $this->normalized,
            'status' => $this->status,
            'message' => $this->message,
            'whatToCheck' => $this->whatToCheck,
        ];
    }
}


