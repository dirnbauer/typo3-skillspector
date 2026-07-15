<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Domain;

final readonly class ScanSummary
{
    /** @param list<string> $messages */
    public function __construct(
        public int $checked,
        public int $danger,
        public int $warning,
        public int $info,
        public array $messages,
    ) {
    }
}

