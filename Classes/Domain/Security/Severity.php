<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Domain\Security;

/**
 * How urgently a human should look at something. The four values are ordered,
 * and a report's level is the highest value it carries — which is the only
 * operation anything ever performs on them.
 *
 * The backing values are the strings persisted on
 * tx_nrllm_skill.tx_skillspector_check_level and serialized into the report
 * JSON, so they are part of the stored format.
 */
enum Severity: string
{
    case None = 'none';
    case Info = 'info';
    case Warning = 'warning';
    case Danger = 'danger';

    /** The more urgent of the two. */
    public function max(self $other): self
    {
        return $other->rank() > $this->rank() ? $other : $this;
    }

    private function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Info => 1,
            self::Warning => 2,
            self::Danger => 3,
        };
    }
}
