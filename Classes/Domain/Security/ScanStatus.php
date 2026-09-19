<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Domain\Security;

/**
 * Whether the NVIDIA SkillSpector subprocess produced a report. The scan is
 * best-effort: only OK carries a risk assessment, and neither of the other
 * two can raise a review level.
 *
 * The backing values are serialized into the stored report JSON.
 */
enum ScanStatus: string
{
    case Ok = 'ok';
    case Unavailable = 'unavailable';
    case Error = 'error';
}
