# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-09-19

Behaviour-preserving restructuring. The stored report JSON, the persisted
`tx_skillspector_check_level` values, the backend module and the command output
are unchanged; the internals that produced them are typed instead of stringly.

### Added

- `Severity`, `LicenseStatus` and `ScanStatus` enums. A severity is now compared with `Severity::max()` — the single operation anything ever performed on one — instead of two private rank tables and `'none'` sentinels scattered over three classes.
- `ExtensionSettings`: the extension configuration read once, typed, with the defaults and the ten-second timeout floor in one place. It is a service built by a factory, so the scanner and the notifier take settings rather than an `ExtensionConfiguration` they each unwrap in a `try`/`catch` of their own.
- Unit tests for the severity ordering, for every settings default and clamp, and for `AdvisoryNotifier`, which had none: a clean run sends nothing, configured recipients are split/trimmed/validated, and the mail leaves its sender to TYPO3.

### Changed

- `SkillCheckReport` derives `level` and `severityCounts` once, in its constructor, and exposes them as properties. They were methods that re-walked the findings on every call — `scanAll()` alone called `level()` three times per skill.
- `SkillspectorScanner::scan()` decides the LLM question once (`$withLlm`) instead of spreading `--no-llm`, the environment and the timeout floor over a nested if/else, and the binary lookup moved into its own method.
- `SkillspectorReport::mapIssue()` looks issue text up through one `firstText()` helper over a declared field order, replacing two four-deep `?:` chains.
- `SkillspectorController::renderList()` builds its rows with `array_map` rather than mutating them through a reference, and the view model defaults every key uniformly instead of five ad-hoc `is_array()` ternaries.
- `SkillInspectionService` builds a `ParsedSkill` in a static mapper and dispatches its counters and messages with exhaustive `match` over `Severity`.
- `Services.yaml` excludes the `Domain` and `Support` trees wholesale instead of listing each directory's direct children.

### Removed

- `AdvisoryNotifier` no longer reads `$GLOBALS['TYPO3_CONF_VARS']['MAIL']` to build a `From:` header with a `no-reply@localhost` fallback. TYPO3's mailer already fills in the system address, and the subject already says who is writing.
- `typo3/cms-scheduler` moved from `require` to `suggest`, and the functional suite no longer loads it. Nothing in `Classes/` references the scheduler — `skillspector:check` is a plain Symfony command, and the scheduler is only one way to run it.
- `SkillCheckFinding::SEVERITY_*`, `LicenseAssessment::STATUS_*` and `SkillspectorReport::STATUS_*` class constants, superseded by the enums; the empty `extra.typo3/cms.Package.providesPackages` stanza.

### Verified

- The nr-llm 0.35 integration holds unchanged: `LlmConfigurationRepository::findDefault()`, `LlmConfiguration::getLlmModel()`, `Provider::getAdapterType()`, `getEndpointUrl()`, `getDecryptedApiKey()` and the twelve `AdapterType` values the credential mapping mirrors are all still present, and the `tx_nrllm_skill` columns this extension reads and extends are unchanged. The constraint is now `^0.34 || ^0.35`.

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
