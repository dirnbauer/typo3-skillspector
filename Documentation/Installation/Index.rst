..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

..  _installation-requirements:

Requirements
============

*   TYPO3 14.3 LTS
*   PHP 8.4 or newer
*   :composer:`netresearch/nr-llm` 0.34 or newer — it owns the skills, the
    :sql:`tx_nrllm_skill` table and the LLM connection this extension can
    borrow credentials from
*   EXT:scheduler, for running the check on a schedule

..  _installation-composer:

Install with Composer
=====================

..  code-block:: bash

    composer require webconsulting/skillspector
    vendor/bin/typo3 extension:setup --extension=skillspector

The extension adds three columns to :sql:`tx_nrllm_skill` —
``tx_skillspector_check_level``, ``tx_skillspector_check_report`` and
``tx_skillspector_checked_at`` — and nothing else to the schema.

..  _installation-scanner:

The optional NVIDIA scanner
===========================

The external scanner is optional in the strict sense: without it the
built-in security and license checks still run, and the report records the
scanner as unavailable.

Install it into the environment of the PHP runtime that will call it:

..  code-block:: bash

    uv tool install git+https://github.com/NVIDIA/skillspector.git

In DDEV, a post-start hook can install it into a persistent pipx cache;
verify it inside the web container:

..  code-block:: bash

    ddev exec skillspector --version

If the binary lives somewhere unusual, point ``skillspectorBinary`` at it
in the extension settings.

..  _installation-verify:

Verify
======

..  code-block:: bash

    vendor/bin/typo3 skillspector:check

The command scans every stored skill, prints the action messages and exits
without changing any skill state. The backend module
:guilabel:`System > Skills Inspector` shows the same reports.
