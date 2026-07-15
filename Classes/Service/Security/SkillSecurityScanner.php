<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Service\Security;

use Webconsulting\Skillspector\Domain\Security\SkillCheckFinding;

/**
 * Scans a skill's instruction body and its supporting code examples for
 * patterns a human should review before trusting the skill. Skills are
 * instructions + example code fed to an AI agent that holds TYPO3 tools, so
 * the two risk classes are (a) prompt-injection / agent-subversion in the body
 * and (b) dangerous or credential-leaking code in the examples.
 *
 * Every match is ADVISORY — the scanner surfaces "what to check", it never
 * decides a skill is malicious. Legitimate security-teaching skills will match
 * (e.g. an example showing eval()); the reviewer judges intent. Severities are
 * calibrated so only genuinely alarming patterns (exposed secrets, pipe-to-shell,
 * exfiltration endpoints) are 'danger'; illustrative code is 'warning'/'info'.
 */
final class SkillSecurityScanner
{
    private const EVIDENCE_MAX = 160;
    private const MATCHES_PER_RULE = 3;
    private const MAX_FINDINGS = 60;

    /**
     * Each rule: id, severity, category, scope (body|code|all), pattern, whatToCheck.
     * `scope` limits body-only rules (prompt injection) from matching example code
     * and vice-versa, cutting false positives.
     *
     * @var list<array{id: string, severity: string, category: string, scope: string, pattern: string, check: string}>
     */
    private const RULES = [
        // --- Dangerous code in examples ------------------------------------
        [
            // DANGER is reserved for context-INDEPENDENTLY catastrophic, irreversible
            // operations: wiping the filesystem root or the user's home, disabling the
            // root guard, formatting a filesystem, or writing to a raw block device.
            // A generic `rm -rf <temp/build/relative path>` is NOT here — that is
            // overwhelmingly benign cleanup and is the warning-level rule below.
            'id' => 'destructive_catastrophic', 'severity' => 'danger', 'category' => 'Catastrophic filesystem/device command', 'scope' => 'all',
            'pattern' => '~\brm\s+(?:-[a-zA-Z]+\s+)+(?:--no-preserve-root\s+)?(?:/|/\*|\~|\~/|\$\{?HOME\}?)(?:\s|$)|\brm\s+[^\n;|&]*--no-preserve-root|\bmkfs\.[a-z0-9]+\b|\bdd\s+if=/dev/[a-z]|>\s*/dev/sd[a-z]~i',
            'check' => 'Catastrophic, irreversible operation (root/home wipe, --no-preserve-root, disk format, raw-device write). Verify it is illustrative only and can never execute against a real path or device.',
        ],
        [
            // Context-DEPENDENT destructive commands: dangerous against a real/system
            // path, harmless against a temp/build dir. Advisory (warning) — it asks the
            // reviewer to confirm the target, and never quarantines on its own.
            'id' => 'destructive_fs', 'severity' => 'warning', 'category' => 'Destructive command', 'scope' => 'all',
            'pattern' => '~\brm\s+-[a-z]*r[a-z]*f[a-z]*\b|\bchmod\s+-R\s*0?777\b~i',
            'check' => 'Destructive/over-permissive command (rm -rf, chmod 777). Confirm the target is always a temp/build/relative path — never a real or system location — and that it is illustrative when shown in docs.',
        ],
        [
            'id' => 'fork_bomb', 'severity' => 'danger', 'category' => 'Fork bomb', 'scope' => 'all',
            'pattern' => '~:\s*\(\s*\)\s*\{\s*:\s*\|\s*:\s*&\s*\}\s*;\s*:~',
            'check' => 'This is a shell fork bomb. Verify it is quoted as an example of what to avoid, not runnable copy-paste.',
        ],
        [
            'id' => 'pipe_to_shell', 'severity' => 'danger', 'category' => 'Remote code execution', 'scope' => 'all',
            'pattern' => '~\b(curl|wget|fetch)\b[^\n`]*\|\s*(sudo\s+)?(ba|z|d)?sh\b~i',
            'check' => 'Piping a downloaded script straight into a shell runs untrusted remote code. Verify the source and that this is not encouraged as-is.',
        ],
        [
            // Function-call form only (no space before "(") so prose like
            // "the system (which…)" does not match — real calls are `system(`.
            'id' => 'code_eval', 'severity' => 'warning', 'category' => 'Dynamic code execution', 'scope' => 'code',
            'pattern' => '~\b(eval|assert|create_function|shell_exec|exec|system|passthru|proc_open|popen)\(|\b(os\.system|subprocess\.(?:call|run|Popen)|__import__|pickle\.loads)\(|\bnew\s+Function\(|\brequire\(\s*[\'"]child_process~i',
            'check' => 'Example runs code/commands dynamically. Verify inputs are not attacker-controlled and the example is safe to copy.',
        ],
        [
            'id' => 'obfuscation', 'severity' => 'warning', 'category' => 'Obfuscated code', 'scope' => 'all',
            'pattern' => '~\beval\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13)\b|\b(base64_decode|gzinflate)\s*\(\s*[\'"][A-Za-z0-9+/]{40,}~i',
            'check' => 'Decoded-then-executed payloads hide their real behaviour. Decode and read what it actually does before trusting it.',
        ],
        [
            'id' => 'sql_concat', 'severity' => 'info', 'category' => 'Possible SQL injection', 'scope' => 'code',
            'pattern' => '~(->query|->exec(?:ute)?|mysqli?_query|pg_query)\s*\(\s*[\'"][^\'"]*[\'"]\s*\.\s*\$|\b(SELECT|INSERT|UPDATE|DELETE)\b[^\n;]*[\'"]\s*\.\s*\$~i',
            'check' => 'Example builds SQL by string-concatenating a variable. If reused, ensure parameters/QueryBuilder are used instead.',
        ],
        // --- Credential / secret leakage -----------------------------------
        [
            'id' => 'private_key', 'severity' => 'danger', 'category' => 'Exposed private key', 'scope' => 'all',
            'pattern' => '~-----BEGIN\s+(RSA|EC|OPENSSH|DSA|PGP)?\s*PRIVATE KEY-----~',
            'check' => 'A private key is embedded in the skill. Rotate it if real, and remove it from the skill content.',
        ],
        [
            'id' => 'api_key', 'severity' => 'danger', 'category' => 'Exposed API credential', 'scope' => 'all',
            'pattern' => '~\bsk-[A-Za-z0-9]{20,}\b|\b(gh[pousr]_[A-Za-z0-9]{30,})\b|\bAKIA[0-9A-Z]{16}\b|\bAIza[0-9A-Za-z\-_]{30,}\b|\bxox[baprs]-[0-9A-Za-z-]{10,}~',
            'check' => 'A live-looking API key/token is present. If real, revoke it and replace with a placeholder.',
        ],
        [
            'id' => 'hardcoded_secret', 'severity' => 'warning', 'category' => 'Hard-coded secret', 'scope' => 'all',
            'pattern' => '~\b(pass(?:word|wd)?|secret|api[_-]?key|access[_-]?token|private[_-]?token)\b\s*[:=]\s*[\'"][^\'"\s]{6,}[\'"]~i',
            'check' => 'A secret appears hard-coded. Verify it is a placeholder, not a real credential.',
        ],
        // --- Data exfiltration ---------------------------------------------
        [
            'id' => 'exfiltration_endpoint', 'severity' => 'danger', 'category' => 'Data exfiltration endpoint', 'scope' => 'all',
            'pattern' => '~\b(webhook\.site|requestbin\.[a-z]+|pipedream\.net|\.ngrok\.io|pastebin\.com/api|discord(?:app)?\.com/api/webhooks|api\.telegram\.org/bot|hooks\.slack\.com)\b~i',
            'check' => 'References an endpoint commonly used to receive exfiltrated data. Verify no page/record data is sent off-site.',
        ],
        // --- Prompt injection / agent subversion (instructions, not code) ---
        [
            'id' => 'instruction_override', 'severity' => 'warning', 'category' => 'Prompt injection', 'scope' => 'body',
            'pattern' => '~\b(ignore|disregard|forget)\s+(all\s+|any\s+)?(previous|prior|earlier|above|the\s+system)\s+(instruction|prompt|rule|message)~i',
            'check' => 'Instruction tries to override earlier/system rules — classic prompt injection. Confirm it is intended, not an attempt to subvert the agent.',
        ],
        [
            'id' => 'reveal_prompt', 'severity' => 'warning', 'category' => 'Prompt injection', 'scope' => 'body',
            'pattern' => '~\b(reveal|print|output|repeat|show)\b[^\n]{0,40}\b(system\s+prompt|your\s+instructions|initial\s+prompt)~i',
            'check' => 'Instruction asks the agent to disclose its system prompt/instructions. Verify this is not an exfiltration attempt.',
        ],
        [
            'id' => 'safety_bypass', 'severity' => 'warning', 'category' => 'Agent subversion', 'scope' => 'body',
            'pattern' => '~\b(bypass|disable|ignore|circumvent|turn\s+off)\b[^\n]{0,40}\b(safety|guard(?:rail)?|sandbox|permission|allowe?d[_-]?tools|confirmation|approval)~i',
            'check' => 'Instruction asks to bypass a safety control (sandbox, permissions, confirmation). Confirm this is legitimate for a local review skill.',
        ],
        [
            'id' => 'unattended_write', 'severity' => 'info', 'category' => 'Autonomy prompt', 'scope' => 'body',
            'pattern' => '~\b(without\s+(asking|confirmation|permission|review)|do\s+not\s+ask|no\s+confirmation|automatically\s+(publish|delete|write|overwrite))\b~i',
            'check' => 'Instruction pushes the agent to act without confirmation. Ensure it cannot cause unreviewed writes/publishes.',
        ],
    ];

    /**
     * @param array<string, string> $files relative path => content (SKILL.md already excluded)
     * @return list<SkillCheckFinding>
     */
    public function scan(string $body, array $files): array
    {
        $findings = [];
        $findings = [...$findings, ...$this->scanContent('body', $body, true, false)];
        foreach ($files as $path => $content) {
            $isCode = $this->looksLikeCode($path);
            $findings = [...$findings, ...$this->scanContent($path, $content, false, $isCode)];
            if (count($findings) >= self::MAX_FINDINGS) {
                break;
            }
        }
        return array_slice($findings, 0, self::MAX_FINDINGS);
    }

    /**
     * @return list<SkillCheckFinding>
     */
    private function scanContent(string $location, string $content, bool $isBody, bool $isCodeFile): array
    {
        if ($content === '') {
            return [];
        }
        $findings = [];
        foreach (self::RULES as $rule) {
            if ($rule['scope'] === 'body' && !$isBody) {
                continue;
            }
            // 'code' rules apply to the body too (fenced examples) and to code files,
            // but not to plain-text/markdown data files.
            if ($rule['scope'] === 'code' && !$isBody && !$isCodeFile) {
                continue;
            }
            if (preg_match_all($rule['pattern'], $content, $matches, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }
            $seen = 0;
            foreach ($matches[0] as $match) {
                if ($seen >= self::MATCHES_PER_RULE) {
                    break;
                }
                $seen++;
                $findings[] = new SkillCheckFinding(
                    $rule['id'],
                    $rule['severity'],
                    $rule['category'],
                    $isBody ? 'body' : $location,
                    $this->evidence($content, (int)$match[1]),
                    $rule['check'],
                );
            }
        }
        return $findings;
    }

    /**
     * The line containing the match, trimmed and truncated, prefixed with its line number.
     */
    private function evidence(string $content, int $offset): string
    {
        $lineNo = substr_count($content, "\n", 0, min($offset, strlen($content))) + 1;
        $lineStart = strrpos(substr($content, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd = strpos($content, "\n", $offset);
        $line = $lineEnd === false ? substr($content, $lineStart) : substr($content, $lineStart, $lineEnd - $lineStart);
        $line = trim($line);
        if (mb_strlen($line) > self::EVIDENCE_MAX) {
            $line = mb_substr($line, 0, self::EVIDENCE_MAX) . '…';
        }
        return 'L' . $lineNo . ': ' . $line;
    }

    private function looksLikeCode(string $path): bool
    {
        return CodeDetection::isCodeFile($path);
    }
}


