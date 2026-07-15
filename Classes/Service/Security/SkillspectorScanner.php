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
 * as part of the advisory review checks. The skill is materialized
 * into a transient directory (regenerated SKILL.md + attachments — the same
 * shape the CLI runner uses) and scanned with `skillspector scan -f json`.
 *
 * Best-effort by design: a missing binary or a failed scan yields an
 * 'unavailable'/'error' report and the built-in checks stand on their own —
 * importing never fails because of SkillSpector. By default the scan runs
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

    public function isEnabled(): bool
    {
        return (bool)Typed::int($this->conf()['skillspectorEnabled'] ?? 1);
    }

    /**
     * Enabled AND the binary resolves — used by the import service to decide
     * whether a stored report whose scan was 'unavailable' should be retried
     * (so installing SkillSpector upgrades existing reports on the next sync).
     */
    public function isAvailable(): bool
    {
        return $this->isEnabled() && $this->resolveBinary() !== null;
    }

    /**
     * @param array<string, string> $files relative path => content (SKILL.md excluded)
     * @return SkillspectorReport|null null when the scan is disabled in the extension configuration
     */
    public function scan(ParsedSkill $skill, array $files): ?SkillspectorReport
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $binary = $this->resolveBinary();
        if ($binary === null) {
            return SkillspectorReport::unavailable(sprintf(
                'SkillSpector binary "%s" not found. Install it with: %s',
                trim(Typed::string($this->conf()['skillspectorBinary'] ?? null)) ?: 'skillspector',
                self::INSTALL_HINT
            ));
        }

        $skillDirectory = '';
        try {
            $skillDirectory = $this->materializeSkill($skill, $files);
            $command = [$binary, 'scan', $skillDirectory, '--format', 'json'];

            // LLM pass: source the provider/key from nr_llm (the "LLM" module),
            // falling back to any SKILLSPECTOR_PROVIDER already present in the
            // environment. When neither yields a provider, degrade to a
            // static-only scan rather than letting SkillSpector hang or fail on
            // its keyless nv_build default.
            $timeout = max(10, Typed::int($this->conf()['skillspectorTimeout'] ?? 120));
            $env = null;
            if ((bool)Typed::int($this->conf()['skillspectorUseLlm'] ?? 0)) {
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
                dirname($skillDirectory),
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
            if ($skillDirectory !== '' && is_dir(dirname($skillDirectory))) {
                GeneralUtility::rmdir(dirname($skillDirectory), true);
            }
        }
    }

    /**
     * Writes the skill back into its on-disk shape (SKILL.md with frontmatter
     * + supporting files) inside var/transient, so SkillSpector sees the same
     * structure a checkout would have — including frontmatter it inspects.
     *
     * @param array<string, string> $files
     */
    private function materializeSkill(ParsedSkill $skill, array $files): string
    {
        // nr_llm identifiers are storage keys such as
        // "1:skills/example/SKILL.md", not safe directory names. Use the
        // declared skill name for the temporary package and keep the storage
        // identifier out of the filesystem path.
        $identifier = preg_replace('/[^a-zA-Z0-9._-]+/', '-', trim($skill->name)) ?: 'skill';
        $runDirectory = Environment::getVarPath() . '/transient/skillspector/spector-' . bin2hex(random_bytes(8));
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

        foreach ($files as $relativePath => $content) {
            $relativePath = trim($relativePath, '/');
            if ($relativePath === '' || str_contains($relativePath, '..')) {
                continue;
            }
            $targetPath = $skillDirectory . '/' . $relativePath;
            GeneralUtility::mkdir_deep(dirname($targetPath));
            file_put_contents($targetPath, $content);
        }

        return $skillDirectory;
    }

    private function resolveBinary(): ?string
    {
        $binary = trim(Typed::string($this->conf()['skillspectorBinary'] ?? null)) ?: 'skillspector';
        if (!str_contains($binary, '/')) {
            return (new ExecutableFinder())->find($binary);
        }
        return is_executable($binary) ? $binary : null;
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
