<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Service\Security;

/**
 * Shared heuristics for "does this skill ship code?" — the license question is
 * only material when a skill provides code examples that could be reused.
 */
final class CodeDetection
{
    /** Programming/scripting languages whose files raise the reuse-license question. */
    private const CODE_EXTENSIONS = [
        'php', 'phtml', 'inc',
        'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'vue', 'svelte',
        'py', 'rb', 'go', 'rs', 'java', 'kt', 'kts', 'scala', 'groovy', 'clj',
        'c', 'h', 'cpp', 'cc', 'hpp', 'cs', 'm', 'mm', 'swift',
        'sh', 'bash', 'zsh', 'fish', 'ps1', 'bat', 'cmd',
        'sql', 'pl', 'pm', 'lua', 'r', 'dart', 'ex', 'exs', 'erl', 'hs',
    ];

    public static function isCodeFile(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, self::CODE_EXTENSIONS, true);
    }

    /**
     * True when the skill ships code: a supporting file in a code language, or a
     * fenced code block in the body carrying a programming-language hint.
     *
     * @param array<string, string> $files relative path => content
     */
    public static function skillHasCode(string $body, array $files): bool
    {
        foreach (array_keys($files) as $path) {
            if (self::isCodeFile($path)) {
                return true;
            }
        }
        // Fenced code block with a language token, e.g. ```php / ```ts / ```bash.
        return (bool)preg_match(
            '~```[ \t]*(php|phtml|js|javascript|ts|typescript|jsx|tsx|py|python|rb|ruby|go|golang|rust|rs|java|kotlin|kt|c|cpp|cs|csharp|sh|bash|shell|zsh|sql|pl|perl|lua|swift|scala|groovy|dart)\b~i',
            $body,
        );
    }
}


