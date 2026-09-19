..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

Everything is configured in :guilabel:`Admin Tools > Settings > Extension
Configuration > skillspector`. There is no TypoScript and no site setting.

..  _configuration-settings:

Extension settings
==================

..  list-table::
    :header-rows: 1

    *   -   Setting
        -   Default
        -   Purpose

    *   -   ``skillspectorEnabled``
        -   ``1``
        -   Run the NVIDIA scanner when its binary exists. Off means the
            built-in security and license checks only.

    *   -   ``skillspectorBinary``
        -   ``skillspector``
        -   Name or absolute path of that binary.

    *   -   ``skillspectorUseLlm``
        -   ``0``
        -   Semantic analysis. **This is the one setting that sends skill
            content off the machine** — see below.

    *   -   ``skillspectorTimeout``
        -   ``120``
        -   Seconds before the scan subprocess is killed. Values below ten
            are read as a typo and raised to ten; the LLM-assisted scan
            additionally uses a floor of 600, because it makes several
            model calls per skill.

    *   -   ``notificationRecipients``
        -   *(empty)*
        -   Comma-separated addresses that receive the action messages of a
            scheduled run. Empty means command output and the TYPO3 log
            only.

..  _configuration-llm:

The LLM-assisted scan
=====================

With ``skillspectorUseLlm`` switched off — the default — the external scan
is static and local: nothing leaves the installation.

Switching it on reuses the connection nr_llm already has. The extension
reads nr_llm's default LLM configuration, takes its provider, model and
vault-stored key, and hands them to the scan subprocess as the environment
variables SkillSpector expects:

..  list-table::
    :header-rows: 1

    *   -   nr_llm adapter type
        -   SkillSpector provider
        -   Environment

    *   -   ``anthropic``
        -   ``anthropic``
        -   ``ANTHROPIC_API_KEY``

    *   -   ``openai``, ``openrouter``, ``mistral``, ``groq``,
            ``together``, ``fireworks``, ``perplexity``, ``ollama``,
            ``azure_openai``, ``custom``
        -   ``openai``
        -   ``OPENAI_API_KEY``, plus ``OPENAI_BASE_URL`` when the provider
            declares an endpoint

    *   -   anything else (for example ``gemini``)
        -   *none*
        -   The scan stays static

So no separate ``SKILLSPECTOR_*`` credentials are needed. The decrypted key
exists only in the environment of one subprocess, for the duration of one
scan; it is never persisted, never logged and never written into a report.
If nr_llm has no default configuration, no provider or no usable key, the
scan silently falls back to static analysis.

..  _configuration-permissions:

Who may run a check
===================

The backend module is declared ``access: admin``, so the backend router
turns non-administrators away before the controller runs; the controller
checks again and renders a *Denied* view if it is ever reached another
way. A check reads every skill body, including whatever secrets an
untrusted skill may contain.
