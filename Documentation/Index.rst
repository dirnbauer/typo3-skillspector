..  include:: /Includes.rst.txt

..  _start:

============
Skillspector
============

:Extension key:
    skillspector

:Package name:
    webconsulting/skillspector

:Version:
    |release|

:Language:
    en

:Author:
    Kurt Dirnbauer, webconsulting business services gmbh

:License:
    This document is published under the
    `Creative Commons BY 4.0 <https://creativecommons.org/licenses/by/4.0/>`__
    license.

:Rendered:
    |today|

----

Advisory security and license review for the skills :composer:`netresearch/nr-llm`
manages. A skill is instructions an LLM will follow; this extension reads
those instructions before the LLM does and tells a human what it found.

Every result is advice. A check never hides, disables or deletes a skill —
it writes a report and, when something needs attention, says what to look
at.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: Introduction

        The three checks, the severity levels and what the reports are
        explicitly not allowed to do.

        ..  card-footer:: :ref:`Read the introduction <introduction>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Installation

        Install the extension, and optionally the NVIDIA scanner it can
        drive.

        ..  card-footer:: :ref:`Install the extension <installation>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Configuration

        Every extension setting, including the one that sends skill
        content to an LLM provider.

        ..  card-footer:: :ref:`Configure the checks <configuration>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Developer reference

        The scan pipeline, the rule table and how to add a check.

        ..  card-footer:: :ref:`Read the reference <developer>`
            :button-style: btn btn-secondary stretched-link

..  toctree::
    :maxdepth: 2
    :titlesonly:

    Introduction/Index
    Installation/Index
    Configuration/Index
    Usage/Index
    Developer/Index

..  toctree::
    :hidden:

    Sitemap
