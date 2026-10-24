#!/usr/bin/env bash
# Run the same checks locally and in CI, using the PHP selected in PATH.
set -euo pipefail
cd "$(dirname "$0")/../.."
suite=ci
php_version=
while getopts 's:p:h' option; do
    case "$option" in
        s) suite="$OPTARG" ;;
        p) php_version="$OPTARG" ;;
        h) echo "Usage: $0 [-s ci|lint|cgl|cgl:fix|phpstan|unit|functional|audit] [-p 8.4|8.5]"; exit 0 ;;
        *) exit 64 ;;
    esac
done
if [[ -n "$php_version" && "$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" != "$php_version" ]]; then
    echo "Run this command using PHP $php_version in PATH." >&2
    exit 1
fi
export typo3DatabaseDriver="${typo3DatabaseDriver:-pdo_sqlite}"
run_lint() {
    find Classes Configuration Tests Build -name '*.php' -print0 | xargs -0 -n1 -P4 php -l >/dev/null
    for file in ext_localconf.php ext_tables.php; do
        [[ ! -f "$file" ]] || php -l "$file" >/dev/null
    done
    php -r '
        libxml_use_internal_errors(true);
        $failed = false;
        foreach (["Resources/Private/Language", "ContentBlocks"] as $directory) {
            if (!is_dir($directory)) { continue; }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
                if ($file->isFile() && $file->getExtension() === "xlf" && !(new DOMDocument())->load($file->getPathname())) {
                    fwrite(STDERR, "Malformed XLIFF: " . $file->getPathname() . PHP_EOL);
                    $failed = true;
                }
            }
        }
        exit($failed ? 1 : 0);
    '
    echo "Lint OK"
}
case "$suite" in
    lint) run_lint ;;
    audit) exec composer audit --abandoned=fail ;;
    cgl) exec composer ci:cgl ;;
    cgl:fix) exec composer fix:cgl ;;
    phpstan) exec composer ci:phpstan ;;
    unit) exec composer ci:tests:unit ;;
    functional) exec composer ci:tests:functional ;;
    ci)
        composer validate --strict
        composer audit --abandoned=fail
        run_lint
        composer ci
        ;;
    *) echo "Unknown suite: $suite" >&2; exit 64 ;;
esac
