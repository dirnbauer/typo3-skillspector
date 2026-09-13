# Skillspector

[![TYPO3 14.3](https://img.shields.io/badge/TYPO3-14.3-orange.svg)](https://get.typo3.org/version/14)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4%2B-777bb3.svg)](https://www.php.net/supported-versions.php)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

## What it is

Advisory security and license review for the skills `netresearch/nr-llm` manages. A skill is instructions an LLM will follow; this extension reads those instructions before the LLM does and tells a human what it found.

Three checks run over the stored SKILL.md body, its frontmatter and its embedded code examples:

| Check | Looks for | Result |
|---|---|---|
| Security scan | Prompt injection, secrets, exfiltration and dangerous commands | `info`, `warning` or `danger` findings |
| License check | A declared license on a skill that ships code, and its compatibility with TYPO3's `GPL-2.0-or-later` ecosystem | A `warning` when code arrives undeclared or incompatible |
| NVIDIA [SkillSpector](https://github.com/NVIDIA/skillspector) | Whatever the external scanner reports, when its binary is installed | A severity floor for the overall level |

Everything is advisory. The report is written to `tx_nrllm_skill`; `enabled`, `orphaned` and `hidden` are never changed by a check. Hiding a skill stays an explicit administrator action. nr_llm does not import referenced scripts or assets, and this extension never fetches or executes them either.

## Requirements

- TYPO3 14.3 LTS
- PHP 8.4+
- `netresearch/nr-llm` 0.34+ (the skills and their storage)
- Optional: the NVIDIA SkillSpector binary

## Install

```bash
composer require webconsulting/skillspector
vendor/bin/typo3 extension:setup --extension=skillspector
```

The external scanner is optional. Install it into the PHP runtime's environment when you want it:

```bash
uv tool install git+https://github.com/NVIDIA/skillspector.git
```

A missing binary is reported as unavailable; the built-in checks keep running.

## Configure

Extension settings (*Admin Tools > Settings > Extension Configuration*):

| Setting | Default | Purpose |
|---|---|---|
| `skillspectorEnabled` | `1` | Run the NVIDIA scanner when its binary exists |
| `skillspectorBinary` | `skillspector` | Path to that binary |
| `skillspectorUseLlm` | `0` | Semantic analysis — **sends skill content to nr_llm's default provider** |
| `skillspectorTimeout` | `120` | Seconds before the subprocess is killed |
| `notificationRecipients` | *(empty)* | Comma-separated addresses for scheduled action messages |

`skillspectorUseLlm` is the only setting that leaves the machine. It reuses the default nr_llm connection — provider, model and the vault-stored key — so no separate `SKILLSPECTOR_*` credentials are needed; the decrypted key lives only in the environment of one scan subprocess and is never persisted or logged.

## Use

Open **System > Skills Inspector**, run **Check all skills**, and read the evidence per skill.

The same scan runs headless as a schedulable Symfony command:

```bash
vendor/bin/typo3 skillspector:check
vendor/bin/typo3 skillspector:check --no-notify
```

It refreshes every report and emits concrete action messages — *review this danger finding*, *this license needs a human decision*, *the external scanner did not complete*. It never hides a skill by itself. With `notificationRecipients` set, those messages are emailed; otherwise they stay in the command output and the TYPO3 log.

## Develop

```bash
composer install
composer ci                   # cgl, phpstan, unit, functional
composer ci:tests:unit
composer ci:tests:functional  # SQLite, no database server needed
composer ci:phpstan           # level 8, no baseline
composer ci:cgl -- --dry-run
docker run --rm -v $PWD:/project ghcr.io/typo3-documentation/render-guides:latest --config=Documentation
```

## Docs

Full manual in [`Documentation/`](Documentation/Index.rst): what each check does, installation, every extension setting, the backend module and the scheduler command, and a developer reference for adding a check.

## License

GPL-2.0-or-later
