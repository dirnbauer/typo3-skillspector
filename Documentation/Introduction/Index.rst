..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

A skill is a :file:`SKILL.md` file: YAML frontmatter, a body of
instructions, often embedded code examples. nr_llm imports them and an
agent holding TYPO3 tools then follows them. That makes a skill a piece of
executable-ish content arriving from outside, and the two risk classes are
obvious once stated — the body can subvert the agent, and the examples can
carry dangerous or credential-leaking code.

This extension reviews both, before a human trusts the skill.

..  _introduction-checks:

The three checks
================

..  list-table::
    :header-rows: 1

    *   -   Check
        -   Looks at
        -   Produces

    *   -   Security scan
        -   The instruction body including code fences
        -   Findings with a severity, a category, the matched evidence and
            a concrete "what to check" sentence

    *   -   License check
        -   The declared license in the frontmatter, and whether the skill
            ships code at all
        -   A warning when code arrives undeclared or under a license that
            needs a human compatibility decision for TYPO3's
            ``GPL-2.0-or-later`` ecosystem

    *   -   NVIDIA SkillSpector
        -   Whatever the external scanner reports, when its binary is
            installed
        -   A severity floor for the overall level, plus the scanner's own
            status and note

..  _introduction-levels:

Severity levels
===============

A report's level is the highest severity it carries:

``none``
    Nothing to review.

``info``
    Worth knowing, no action implied.

``warning``
    A human decision is needed — most license findings land here, as does
    illustrative but dangerous-looking code.

``danger``
    Something genuinely alarming: an exposed private key or API key, a
    pipe-to-shell, a fork bomb, an exfiltration endpoint, a catastrophic
    filesystem or device command.

The severities are deliberately calibrated. A skill that *teaches* security
will match rules — showing :php:`eval()` in an example is a ``warning``,
not a ``danger`` — because the reviewer, not the scanner, judges intent.

..  _introduction-boundaries:

What a check never does
=======================

*   It never changes ``enabled``, ``orphaned`` or ``hidden``. Hiding a
    skill is an explicit administrator action.
*   It never fetches or executes anything the skill references. nr_llm does
    not import referenced scripts or assets, and neither does this
    extension.
*   It never sends skill content anywhere, unless
    :ref:`skillspectorUseLlm <configuration-settings>` is switched on —
    which is off by default and documented as the one setting that leaves
    the machine.
