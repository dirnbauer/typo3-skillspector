# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-13

First stable release. No behaviour of the checks themselves changed; this release is about making the extension releasable.

### Added

- Functional test suite: the advisory checks run through the DI container against `SKILL.md` fixtures — one clean, one shipping code without a license, one that pipes a remote script into a shell and must be flagged `danger` — plus a test that `scanAll()` persists one report per stored skill.
- `Documentation/` as a rendered TYPO3 manual — introduction, installation, configuration, usage and a developer reference covering the rule table and the subprocess.
- A CI workflow: lint, coding standards, PHPStan, unit tests on PHP 8.4 and 8.5, and functional tests against MariaDB 10.11. The extension had none.
- `CHANGELOG.md`, `.php-cs-fixer.dist.php` on `typo3/coding-standards`, and the `composer ci`, `ci:cgl`, `ci:phpstan`, `ci:tests:unit` and `ci:tests:functional` scripts.

### Changed

- Declared version is 1.0.0 (was 0.1.0-beta). The extension stays composer-only — there is no `ext_emconf.php` and none was added.
- Tests run through the TYPO3 testing framework; the functional suite defaults to SQLite so a local run needs no database server.
- PHPStan now analyses `Configuration/` and `Tests/` as well as `Classes/`, at level 8 with the TYPO3 and PHPUnit extensions and no baseline.
- README follows the shared structure and documents every extension setting, including that `skillspectorUseLlm` is the only one that sends skill content off the machine.

### Fixed

- Five PHPStan findings that the widened analysis surfaced: the process fixture read `$argv` without a guarantee that it exists, and three data providers declared untyped iterables.

### Verified

- The nr-llm 0.34 integration still holds: `LlmConfigurationRepository::findDefault()`, `Provider::getAdapterType()` and the `AdapterType` values the credential mapping mirrors are all unchanged.

[1.0.0]: https://github.com/dirnbauer/skillspector/releases/tag/v1.0.0
