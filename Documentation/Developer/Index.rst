..  include:: /Includes.rst.txt

..  _developer:

===================
Developer reference
===================

..  _developer-pipeline:

The scan pipeline
=================

..  code-block:: text

    SkillInspectionService::scanAll()
      └─ per tx_nrllm_skill row → ParsedSkill
           └─ SkillCheckService::check()
                ├─ SkillSecurityScanner  → findings
                ├─ LicenseChecker        → license assessment
                └─ SkillspectorScanner   → optional external report
           └─ SkillCheckReport → persisted on the skill row

:php:`SkillInspectionService` is the only class that touches the database.
:php:`SkillCheckService` and everything below it are pure functions of a
:php:`ParsedSkill`, which is why the whole rule set is unit-testable
without TYPO3.

..  _developer-model:

The typed model
===============

Three enums carry everything that used to travel as a bare string, so the
stored format is declared in one place and no comparison can be misspelt:

..  list-table::
    :header-rows: 1

    *   -   Enum
        -   Cases
        -   Where the values show up

    *   -   :php:`Severity`
        -   ``none``, ``info``, ``warning``, ``danger``
        -   The finding severities, the report level and the
            ``tx_skillspector_check_level`` column

    *   -   :php:`LicenseStatus`
        -   ``compatible``, ``review``, ``incompatible``, ``unknown``
        -   The license assessment

    *   -   :php:`ScanStatus`
        -   ``ok``, ``unavailable``, ``error``
        -   Whether the external subprocess produced a report

:php:`Severity::max()` is the only operation performed on a severity —
"the worst thing in the report wins" — so :php:`SkillCheckReport` derives
its :php:`$level` and :php:`$severityCounts` once, in its constructor,
rather than recomputing them per reader.

The extension configuration is read the same way: :php:`ExtensionSettings`
is built once per container from :php:`ExtensionConfiguration`, holds the
defaults and the timeout floor, and is what the scanner and the notifier
are injected with. Nothing else reads a setting, and a unit test builds
one with :php:`ExtensionSettings::fromArray()`.

..  _developer-rules:

The rule table
==============

:php:`SkillSecurityScanner` holds a constant list of rules, each with an
id, a severity, a category, a regular expression and the sentence a
reviewer reads. Fifteen ship today:

..  list-table::
    :header-rows: 1

    *   -   Severity
        -   Rules

    *   -   :php:`Severity::Danger`
        -   ``destructive_catastrophic``, ``fork_bomb``,
            ``pipe_to_shell``, ``private_key``, ``api_key``,
            ``exfiltration_endpoint``

    *   -   :php:`Severity::Warning`
        -   ``destructive_fs``, ``code_eval``, ``obfuscation``,
            ``hardcoded_secret``, ``instruction_override``,
            ``reveal_prompt``, ``safety_bypass``

    *   -   :php:`Severity::Info`
        -   ``sql_concat``, ``unattended_write``

Adding a rule means adding one entry. Two things matter when you do:

*   ``Danger`` is reserved for patterns that are catastrophic regardless
    of context. A generic :bash:`rm -rf build/` is a ``Warning``, because
    the reviewer has to look at the target; wiping ``/`` is a ``Danger``,
    because no target makes it fine.
*   The ``check`` sentence is the product. It is what a human reads at
    three in the morning, so it says what to verify, not what matched.

Every rule reports at most three matches, and evidence is trimmed, so one
noisy skill cannot flood a report.

..  _developer-subprocess:

The external scanner
====================

:php:`SkillspectorScanner` writes the skill into a private directory under
:file:`var/transient/skillspector/`, runs the binary against that directory
with :bash:`--no-llm` unless the LLM scan is enabled *and* credentials
resolve, and removes only what it created — a concurrent scan's files are
left alone. The directory name is derived defensively: a skill called
``.``, ``..`` or ``''`` can never escape its own folder.

Credentials come from :php:`NrLlmScanCredentials`, which reads nr_llm's
default configuration and maps the adapter type onto SkillSpector's
provider environment. The mapping is a static, pure method, so it is
tested without nr_llm being bootstrapped at all.

The scanner's JSON output is parsed by
:php:`SkillspectorReport::fromScanOutput()`, which tolerates log noise
before the document and turns anything unparseable into an ``error``
report rather than an exception. Issue field names are looked up in
preference order — SkillSpector 2.x ships ``finding`` / ``remediation``,
the project README documents ``title`` / ``recommendation`` — so both
shapes map onto the same finding.

..  _developer-tests:

Tests
=====

..  code-block:: bash

    composer ci                   # cgl, phpstan, unit, functional
    composer ci:tests:unit
    composer ci:tests:functional  # SQLite by default, no database server
    composer ci:phpstan           # level 8, no baseline
    composer ci:cgl -- --dry-run

The unit suite covers the rule table, the license matrix, the report value
objects, the severity ordering, the settings defaults and clamps, the
notifier's recipient handling, the subprocess handling against a process
fixture, and the credential mapping.

The functional suite boots TYPO3 with nr_llm and this extension and runs
the checks through the DI container against :file:`SKILL.md` fixtures in
:file:`Tests/Functional/Fixtures/Skills/` — one clean, one shipping code
without a license, and one that pipes a remote script into a shell and
must be flagged ``Danger``. A further test stores those three skills in
:sql:`tx_nrllm_skill` and asserts :php:`scanAll()` persists one report per
skill at the right level.

Third-party deprecations do not fail the suites: the PHPUnit configuration
sets :xml:`<source ignoreIndirectDeprecations="true">`, so
``failOnDeprecation`` stays pointed at this extension's own code.

..  _developer-ci:

Continuous integration
======================

This package is consumed by the webconsulting TYPO3 lab as a Composer path
repository and has no repository of its own, so
:file:`.github/workflows/ci.yml` does not run anywhere — GitHub Actions
only reads workflows at a repository root. It is kept as the pipeline to
restore if the package is ever extracted. What actually gates the package
today is :bash:`Build/Scripts/runTests.sh -s quality` in the lab (lint,
PHPStan, unit) plus the :bash:`composer ci` scripts here.
