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

    *   -   ``danger``
        -   ``destructive_catastrophic``, ``fork_bomb``,
            ``pipe_to_shell``, ``private_key``, ``api_key``,
            ``exfiltration_endpoint``

    *   -   ``warning``
        -   ``destructive_fs``, ``code_eval``, ``obfuscation``,
            ``hardcoded_secret``, ``instruction_override``,
            ``reveal_prompt``, ``safety_bypass``

    *   -   ``info``
        -   ``sql_concat``, ``unattended_write``

Adding a rule means adding one entry. Two things matter when you do:

*   ``danger`` is reserved for patterns that are catastrophic regardless
    of context. A generic :bash:`rm -rf build/` is a ``warning``, because
    the reviewer has to look at the target; wiping ``/`` is a ``danger``,
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
with :bash:`--no-llm` unless the LLM scan is enabled, and removes only what
it created — a concurrent scan's files are left alone. The directory name
is derived defensively: a skill called ``.``, ``..`` or ``''`` can never
escape its own folder.

Credentials come from :php:`NrLlmScanCredentials`, which reads nr_llm's
default configuration and maps the adapter type onto SkillSpector's
provider environment. The mapping is a static, pure method, so it is
tested without nr_llm being bootstrapped at all.

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
objects, the subprocess handling against a process fixture, and the
credential mapping.

The functional suite boots TYPO3 with nr_llm and this extension and runs
the checks through the DI container against :file:`SKILL.md` fixtures in
:file:`Tests/Functional/Fixtures/Skills/` — one clean, one shipping code
without a license, and one that pipes a remote script into a shell and
must be flagged ``danger``. A further test stores those three skills in
:sql:`tx_nrllm_skill` and asserts :php:`scanAll()` persists one report per
skill at the right level.

Third-party deprecations do not fail the suites: the PHPUnit configuration
sets :xml:`<source ignoreIndirectDeprecations="true">`, so
``failOnDeprecation`` stays pointed at this extension's own code.
