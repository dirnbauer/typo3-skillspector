<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Domain\Security;

/**
 * How a skill's declared license relates to TYPO3's own GPL-2.0-or-later.
 * Advisory: anything but COMPATIBLE asks a human to decide, and nothing here
 * ever disables a skill.
 *
 * The backing values are serialized into the stored report JSON.
 */
enum LicenseStatus: string
{
    /** Clearly usable in a GPL-2.0-or-later project (permissive or GPL-2-compatible). */
    case Compatible = 'compatible';
    /** Usable only under conditions (e.g. the "or-later"/v3 path); review before redistributing. */
    case Review = 'review';
    /** Non-free or GPL-incompatible; redistribution under GPL-2.0-or-later is doubtful. */
    case Incompatible = 'incompatible';
    /** No license declared. */
    case Unknown = 'unknown';
}
