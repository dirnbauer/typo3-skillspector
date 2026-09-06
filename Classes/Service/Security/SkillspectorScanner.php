<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Service\Security;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillspector\Domain\ParsedSkill;
use Webconsulting\Skillspector\Domain\Security\SkillspectorReport;
use Webconsulting\Skillspector\Support\Typed;

/**
 * Runs NVIDIA SkillSpector (github.com/NVIDIA/skillspector) against one skill
 * as part of the advisory review checks. The imported SKILL.md is regenerated
 * in a transient directory and scanned with `skillspector scan -f json`.
 *
 * Best-effort by design: a missing binary or a failed scan yields an
 * 'unavailable'/'error' report and the built-in checks stand on their own —
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
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly NrLlmScanCredentials $nrLlmScanCredentials,
    ) {
    }

    /**
     * @return SkillspectorReport|null null when the scan is disabled in the extension configuration
     */
    public function scan(ParsedSkill $skill): ?SkillspectorReport
    {
        $configuration = $this->conf();
        if (!Typed::int($configuration['skillspectorEnabled'] ?? 1)) {
            return null;
        }
        $binaryName = trim(Typed::string($configuration['skillspectorBinary'] ?? null)) ?: 'skillspector';
        if (str_contains($binaryName, '/')) {
            $binary = is_executable($binaryName) ? $binaryName : null;
        } else {
            $binary = (new ExecutableFinder())->find($binaryName);
        }
        if ($binary === null) {
            return SkillspectorReport::unavailable(sprintf(
                'SkillSpector binary "%s" not found. Install it with: %s',
                $binaryName,
                self::INSTALL_HINT
            ));
        }

        $runDirectory = Environment::getVarPath() . '/transient/skillspector/spector-' . bin2hex(random_bytes(8));
        try {
            $skillDirectory = $this->materializeSkill($skill, $runDirectory);
            $command = [$binary, 'scan', $skillDirectory, '--format', 'json'];

            // LLM pass: source the provider/key from nr_llm (the "LLM" module),
            // falling back to any SKILLSPECTOR_PROVIDER already present in the
            // environment. When neither yields a provider, degrade to a
            // static-only scan rather than letting SkillSpector hang or fail on
            // its keyless nv_build default.
            $timeout = max(10, Typed::int($configuration['skillspectorTimeout'] ?? 120));
            $env = null;
            if (Typed::int($configuration['skillspectorUseLlm'] ?? 0)) {
                $env = $this->nrLlmScanCredentials->resolve();
                if ($env !== null || getenv('SKILLSPECTOR_PROVIDER') !== false) {
                    $timeout = max($timeout, self::LLM_TIMEOUT_FLOOR);
                } else {
                    $command[] = '--no-llm';
                }
            } else {
                $command[] = '--no-llm';
            }

            $process = new Process(
                $command,
                $runDirectory,
                $env,
                null,
                $timeout
            );
            $process->run();

            // Exit code 0 = scan ok (score <= 50), 1 = scan ok (score > 50),
            // 2+ = the scan itself failed.
            if ((int)$process->getExitCode() > 1) {
                return SkillspectorReport::error(sprintf(
                    'SkillSpector scan failed (exit %d): %s',
                    (int)$process->getExitCode(),
                    mb_substr(trim($process->getErrorOutput() . "\n" . $process->getOutput()), 0, self::OUTPUT_SNIPPET_MAX)
                ));
            }
            return SkillspectorReport::fromScanOutput($process->getOutput());
        } catch (\Throwable $e) {
            return SkillspectorReport::error('SkillSpector scan failed: ' . mb_substr($e->getMessage(), 0, self::OUTPUT_SNIPPET_MAX));
        } finally {
            GeneralUtility::rmdir($runDirectory, true);
        }
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

    /**
     * @return array<string, mixed>
     */
    private function conf(): array
    {
        try {
            return Typed::stringKeyedArray($this->extensionConfiguration->get('skillspector'));
        } catch (\Throwable) {
            return [];
        }
    }
}
