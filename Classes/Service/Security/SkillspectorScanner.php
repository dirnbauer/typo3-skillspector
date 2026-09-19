<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Service\Security;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillspector\Domain\ExtensionSettings;
use Webconsulting\Skillspector\Domain\ParsedSkill;
use Webconsulting\Skillspector\Domain\Security\SkillspectorReport;

/**
 * Runs NVIDIA SkillSpector (github.com/NVIDIA/skillspector) against one skill
 * as part of the advisory review checks. The imported SKILL.md is regenerated
 * in a transient directory and scanned with `skillspector scan -f json`.
 *
 * Best-effort by design: a missing binary or a failed scan yields an
 * UNAVAILABLE/ERROR report and the built-in checks stand on their own —
 * inspection can continue when SkillSpector fails. By default the scan runs
 * static-only (`--no-llm`), so it needs no API key and no content leaves the
 * machine; the LLM-assisted pass can be enabled in the extension
 * configuration (mind the data-egress note there).
 *
 * Install: `uv tool install git+https://github.com/NVIDIA/skillspector.git`
 */
final class SkillspectorScanner
{
    public const INSTALL_HINT = 'uv tool install git+https://github.com/NVIDIA/skillspector.git';

    private const OUTPUT_SNIPPET_MAX = 300;

    /**
     * LLM-assisted scans make several model calls per skill, so the static
     * default timeout is far too low — this is the floor applied when the LLM
     * pass actually runs.
     */
    private const LLM_TIMEOUT_FLOOR = 600;

    public function __construct(
        private readonly ExtensionSettings $settings,
        private readonly NrLlmScanCredentials $nrLlmScanCredentials,
    ) {}

    /**
     * @return SkillspectorReport|null null when the scan is disabled in the extension configuration
     */
    public function scan(ParsedSkill $skill): ?SkillspectorReport
    {
        if (!$this->settings->scanWithSkillspector) {
            return null;
        }

        $binary = $this->locateBinary();
        if ($binary === null) {
            return SkillspectorReport::unavailable(sprintf(
                'SkillSpector binary "%s" not found. Install it with: %s',
                $this->settings->binary,
                self::INSTALL_HINT,
            ));
        }

        // The LLM pass needs a provider: nr_llm's default connection (the "LLM"
        // module), or a SKILLSPECTOR_PROVIDER already in the environment. With
        // neither, degrade to a static scan rather than let SkillSpector hang
        // or fail on its keyless nv_build default.
        $environment = $this->settings->useLlm ? $this->nrLlmScanCredentials->resolve() : null;
        $withLlm = $this->settings->useLlm
            && ($environment !== null || getenv('SKILLSPECTOR_PROVIDER') !== false);

        $runDirectory = Environment::getVarPath() . '/transient/skillspector/spector-' . bin2hex(random_bytes(8));
        try {
            $command = [$binary, 'scan', $this->materializeSkill($skill, $runDirectory), '--format', 'json'];
            if (!$withLlm) {
                $command[] = '--no-llm';
            }

            $process = new Process(
                $command,
                $runDirectory,
                $environment,
                null,
                $withLlm ? max($this->settings->timeout, self::LLM_TIMEOUT_FLOOR) : $this->settings->timeout,
            );
            $process->run();

            // Exit code 0 = scan ok (score <= 50), 1 = scan ok (score > 50),
            // 2+ = the scan itself failed.
            $exitCode = $process->getExitCode() ?? 0;
            if ($exitCode > 1) {
                return SkillspectorReport::error(sprintf(
                    'SkillSpector scan failed (exit %d): %s',
                    $exitCode,
                    mb_substr(trim($process->getErrorOutput() . "\n" . $process->getOutput()), 0, self::OUTPUT_SNIPPET_MAX),
                ));
            }

            return SkillspectorReport::fromScanOutput($process->getOutput());
        } catch (\Throwable $e) {
            return SkillspectorReport::error('SkillSpector scan failed: ' . mb_substr($e->getMessage(), 0, self::OUTPUT_SNIPPET_MAX));
        } finally {
            GeneralUtility::rmdir($runDirectory, true);
        }
    }

    /** A configured path is taken as-is; a bare name is resolved through PATH. */
    private function locateBinary(): ?string
    {
        $configured = $this->settings->binary;
        if (str_contains($configured, '/')) {
            return is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder())->find($configured);
    }

    /**
     * Writes SKILL.md inside the directory owned by this scan. nr_llm does
     * not import supporting files, so only its stored frontmatter/body are scanned.
     */
    private function materializeSkill(ParsedSkill $skill, string $runDirectory): string
    {
        // A package name must be one path segment, never "." or "..".
        $identifier = trim(preg_replace('/[^a-zA-Z0-9._-]+/', '-', trim($skill->name)) ?? '', '.') ?: 'skill';
        $skillDirectory = $runDirectory . '/' . $identifier;
        GeneralUtility::mkdir_deep($skillDirectory);

        $frontmatter = [
            'name' => $identifier,
            'description' => $skill->description,
        ];
        if ($skill->allowedTools !== '') {
            $frontmatter['allowed-tools'] = $skill->allowedTools;
        }
        // Extra frontmatter (license, metadata, ...) is part of what
        // SkillSpector analyses — pass it through unchanged.
        $frontmatter += $skill->metadata;
        file_put_contents(
            $skillDirectory . '/SKILL.md',
            "---\n" . Yaml::dump($frontmatter) . "---\n\n" . $skill->body . "\n"
        );

        return $skillDirectory;
    }
}
