# Skills Inspector for TYPO3

`webconsulting/skillspector` adds advisory security and license review to the
skills managed by `netresearch/nr-llm`.

## Checks

- prompt-injection, secret, exfiltration, and dangerous-command patterns;
- NVIDIA [SkillSpector](https://github.com/NVIDIA/skillspector) when installed;
- declared-license compatibility guidance for code copied into TYPO3's
  `GPL-2.0-or-later` ecosystem.

The inspector stores its report on `tx_nrllm_skill`. It never changes
`enabled`, `orphaned`, or `hidden` during a check.

## Install

```bash
composer require webconsulting/skillspector:@dev
uv tool install git+https://github.com/NVIDIA/skillspector.git
vendor/bin/typo3 extension:setup
```

Open **System → Skills Inspector**, run **Check all skills**, and review the
evidence. **Hide** and **Unhide** are explicit administrator actions. nr_llm's
own **enabled** toggle remains in **Admin Tools → LLM → Skills**.

## Scheduler and notifications

`skillspector:check` is a native schedulable Symfony command:

```bash
vendor/bin/typo3 skillspector:check
```

It refreshes reports and emits concrete action messages. It does not hide a
skill automatically. Configure comma-separated `notificationRecipients` in
the extension settings to email those messages; without recipients they remain
in command output and TYPO3 logs. Use `--no-notify` to suppress notification
delivery for a run.

Static SkillSpector analysis is the default and sends no skill content away.
Enabling `skillspectorUseLlm` reuses the default nr_llm provider and sends skill
content to that provider for semantic analysis.

