<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Tests\Unit\Domain\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webconsulting\Skillspector\Domain\Security\Severity;

final class SeverityTest extends TestCase
{
    /**
     * @return iterable<string, array{Severity, Severity, Severity}>
     */
    public static function pairs(): iterable
    {
        yield 'danger beats warning' => [Severity::Warning, Severity::Danger, Severity::Danger];
        yield 'order does not matter' => [Severity::Danger, Severity::Warning, Severity::Danger];
        yield 'warning beats info' => [Severity::Info, Severity::Warning, Severity::Warning];
        yield 'info beats none' => [Severity::None, Severity::Info, Severity::Info];
        yield 'none never wins' => [Severity::Danger, Severity::None, Severity::Danger];
        yield 'equal stays equal' => [Severity::Warning, Severity::Warning, Severity::Warning];
    }

    #[DataProvider('pairs')]
    public function testMaxReturnsTheMoreUrgentOfTwo(Severity $left, Severity $right, Severity $expected): void
    {
        self::assertSame($expected, $left->max($right));
    }

    public function testTheBackingValuesAreTheStoredFormat(): void
    {
        // Persisted on tx_nrllm_skill and read back by the backend module.
        self::assertSame(
            ['none', 'info', 'warning', 'danger'],
            array_map(static fn(Severity $severity): string => $severity->value, Severity::cases()),
        );
    }
}
