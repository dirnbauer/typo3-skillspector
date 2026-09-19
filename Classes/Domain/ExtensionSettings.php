<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Domain;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Webconsulting\Skillspector\Support\Typed;

/**
 * The extension configuration, read once and typed. Everything that consumes a
 * setting takes this object, so the string-keyed, string-valued shape TYPO3
 * stores exists in exactly one place — together with the defaults and the
 * timeout floor that used to be scattered over the call sites.
 *
 * Registered as a service built by {@see self::load()}; unit tests build one
 * with the constructor or {@see self::fromArray()} and need no TYPO3 at all.
 */
final readonly class ExtensionSettings
{
    private const DEFAULT_BINARY = 'skillspector';
    private const DEFAULT_TIMEOUT = 120;

    /** A scan that may not take ten seconds cannot succeed; treat lower values as a typo. */
    private const MINIMUM_TIMEOUT = 10;

    /**
     * @param list<string> $notificationRecipients
     */
    public function __construct(
        /** Run NVIDIA SkillSpector as part of a check. */
        public bool $scanWithSkillspector,
        /** Binary name (resolved through PATH) or an absolute path. */
        public string $binary,
        /** Let SkillSpector use nr_llm's provider — the one setting that sends skill content off the machine. */
        public bool $useLlm,
        /** Subprocess timeout in seconds. */
        public int $timeout,
        /** Addresses that receive the action messages of a scheduled run. */
        public array $notificationRecipients,
    ) {}

    public static function load(ExtensionConfiguration $extensionConfiguration): self
    {
        try {
            $raw = Typed::stringKeyedArray($extensionConfiguration->get('skillspector'));
        } catch (\Throwable) {
            // Not configured yet (or the extension is mid-install): use the defaults.
            $raw = [];
        }

        return self::fromArray($raw);
    }

    /**
     * @param array<string, mixed> $raw values as TYPO3 stores them, i.e. strings
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            Typed::int($raw['skillspectorEnabled'] ?? 1) !== 0,
            trim(Typed::string($raw['skillspectorBinary'] ?? null)) ?: self::DEFAULT_BINARY,
            Typed::int($raw['skillspectorUseLlm'] ?? 0) !== 0,
            max(self::MINIMUM_TIMEOUT, Typed::int($raw['skillspectorTimeout'] ?? self::DEFAULT_TIMEOUT)),
            self::emailList(Typed::string($raw['notificationRecipients'] ?? null)),
        );
    }

    /**
     * @return list<string>
     */
    private static function emailList(string $commaSeparated): array
    {
        $candidates = array_map(trim(...), explode(',', $commaSeparated));

        return array_values(array_filter(
            $candidates,
            static fn(string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }
}
