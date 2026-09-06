# Skills Inspector for TYPO3

`webconsulting/skillspector` adds advisory security and license review to the
skills managed by `netresearch/nr-llm`.

## Checks

- prompt-injection, secret, exfiltration, and dangerous-command patterns;
- NVIDIA [SkillSpector](https://github.com/NVIDIA/skillspector) when installed;
- declared-license compatibility guidance for code copied into TYPO3's
  `GPL-2.0-or-later` ecosystem.

Checks cover the stored SKILL.md instructions, frontmatter and embedded code
examples. nr_llm does not import referenced scripts or assets; the inspector
does not fetch or scan those files.

The inspector stores its report on `tx_nrllm_skill`. It never changes
`enabled`, `orphaned`, or `hidden` during a check.

## Install

This lab installs the local package through the root Composer path repository.
It requires PHP 8.4 and TYPO3 14.3. Package metadata lives in `composer.json`.

```bash
ddev composer install
ddev typo3 extension:setup
```

The DDEV post-start hook in `.ddev/config.skillspector.yaml` installs the
optional NVIDIA scanner into its persistent pipx cache. Check it inside the
web container with `ddev exec skillspector --version`. A missing binary leaves
the built-in checks available and is reported as unavailable. Outside DDEV,
install SkillSpector in the PHP runtime's environment with
`uv tool install git+https://github.com/NVIDIA/skillspector.git`.

Open **System → Skills Inspector**, run **Check all skills**, and review the
evidence. **Hide** and **Unhide** are explicit administrator actions. nr_llm's
own **enabled** toggle remains in **Admin Tools → LLM → Skills**.

## Scheduler and notifications

`skillspector:check` is a native schedulable Symfony command:

```bash
ddev typo3 skillspector:check
```

It refreshes reports and emits concrete action messages. It does not hide a
skill automatically. Configure comma-separated `notificationRecipients` in
the extension settings to email those messages; without recipients they remain
in command output and TYPO3 logs. Use `--no-notify` to suppress notification
delivery for a run.

Static SkillSpector analysis is the default and sends no skill content away.
Enabling `skillspectorUseLlm` reuses the default nr_llm provider and sends skill
content to that provider for semantic analysis.

## Development

Run from the lab root:

```bash
ddev exec Build/Scripts/runTests.sh -s unit -p 8.4
ddev exec Build/Scripts/runTests.sh -s phpstan -p 8.4
```

Both suites include this package. The full `-s quality` suite also checks PHP
syntax, Composer metadata, YAML, the frontend build and dependency audits.
