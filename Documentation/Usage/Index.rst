..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

..  _usage-module:

The backend module
==================

:guilabel:`System > Skills Inspector` lists every skill nr_llm knows,
with its last check level and the evidence behind it. :guilabel:`Check all
skills` re-runs the scan.

The module is administrators only; everyone else sees the *Denied* view. A
check reads every skill body verbatim, including whatever an untrusted
skill happens to carry.

Hiding a skill remains a separate, explicit action. nr_llm's own
``enabled`` toggle stays where it is, in :guilabel:`Admin Tools > LLM >
Skills`.

..  _usage-command:

The scheduled check
===================

:bash:`skillspector:check` is a native schedulable Symfony command, so it
can be run by hand or registered as a scheduler task:

..  code-block:: bash

    vendor/bin/typo3 skillspector:check
    vendor/bin/typo3 skillspector:check --no-notify

It refreshes every report and prints concrete action messages:

*   *danger finding(s). Review immediately and hide the skill manually if
    it is not trusted.*
*   *warning findings require review; no state was changed.*
*   *license <x> requires human compatibility review before copying code
    into TYPO3.*
*   *NVIDIA SkillSpector did not complete (<status>).*

With ``notificationRecipients`` configured, the same messages are emailed.
:bash:`--no-notify` suppresses delivery for one run; the messages still
appear in the command output and the log.

..  _usage-report:

Reading a report
================

Each report is stored as JSON on the skill and carries:

``level``
    The highest severity in the report.

``severityCounts``
    How many findings per severity.

``findings``
    One entry per match: its rule id, severity, category, the trimmed
    evidence and the sentence describing what to check.

``license``
    The declared license, whether it needs review, and why.

``hasCode``
    Whether the body contains a fenced code block in a programming
    language — a data fence such as ``json`` does not count.

``skillspector``
    The external scanner's status, note and risk assessment, or ``null``
    when it did not run.

``generatedAt``
    The timestamp of the check.
