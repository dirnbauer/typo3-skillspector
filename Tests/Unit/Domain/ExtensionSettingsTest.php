<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webconsulting\Skillspector\Domain\ExtensionSettings;

/**
 * TYPO3 stores extension configuration as strings and an unconfigured
 * extension has no values at all, so every default and every clamp lives
 * here rather than at the call sites.
 */
final class ExtensionSettingsTest extends TestCase
{
    public function testAnUnconfiguredExtensionScansWithTheDefaults(): void
    {
        $settings = ExtensionSettings::fromArray([]);

        self::assertTrue($settings->scanWithSkillspector);
        self::assertSame('skillspector', $settings->binary);
        self::assertFalse($settings->useLlm);
        self::assertSame(120, $settings->timeout);
        self::assertSame([], $settings->notificationRecipients);
    }

    public function testStringValuesAreReadAsTheTypesTheyMean(): void
    {
        $settings = ExtensionSettings::fromArray([
            'skillspectorEnabled' => '0',
            'skillspectorBinary' => '  /opt/bin/skillspector  ',
            'skillspectorUseLlm' => '1',
            'skillspectorTimeout' => '900',
        ]);

        self::assertFalse($settings->scanWithSkillspector);
        self::assertSame('/opt/bin/skillspector', $settings->binary);
        self::assertTrue($settings->useLlm);
        self::assertSame(900, $settings->timeout);
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function timeouts(): array
    {
        return [
            'below the floor is raised' => ['3', 10],
            'empty is the floor, not zero' => ['', 10],
            'nonsense is the floor' => ['soon', 10],
            'a plausible value is kept' => ['45', 45],
        ];
    }

    #[DataProvider('timeouts')]
    public function testTheTimeoutNeverDropsBelowTheFloor(mixed $configured, int $expected): void
    {
        self::assertSame($expected, ExtensionSettings::fromArray(['skillspectorTimeout' => $configured])->timeout);
    }

    public function testAnEmptyBinaryFallsBackToThePathLookup(): void
    {
        self::assertSame('skillspector', ExtensionSettings::fromArray(['skillspectorBinary' => '   '])->binary);
    }

    public function testRecipientsAreSplitTrimmedAndValidated(): void
    {
        $settings = ExtensionSettings::fromArray([
            'notificationRecipients' => ' ops@example.com , not-an-address, , security@example.com ',
        ]);

        self::assertSame(['ops@example.com', 'security@example.com'], $settings->notificationRecipients);
    }

    public function testNoRecipientsWhenNoneAreConfigured(): void
    {
        self::assertSame([], ExtensionSettings::fromArray(['notificationRecipients' => ''])->notificationRecipients);
    }
}
