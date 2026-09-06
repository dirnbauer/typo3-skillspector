#!/usr/bin/env php
<?php

declare(strict_types=1);

// Local process fixture: no network calls or actual skill execution.
$directory = realpath($argv[2]);
if ($directory === false || dirname($directory) !== getcwd() || !in_array('--no-llm', $argv, true)) {
    fwrite(STDERR, 'The scan must stay inside its own directory and use static analysis.');
    exit(2);
}
$markdown = file_get_contents($directory . '/SKILL.md');
if ($markdown === false || str_contains($markdown, 'FAIL_SCAN')) {
    fwrite(STDERR, 'Fixture scan failed.');
    exit(2);
}
echo json_encode([
    'risk_assessment' => ['score' => 0, 'severity' => 'LOW', 'recommendation' => 'SAFE'],
    'metadata' => ['skillspector_version' => 'fixture'],
    'issues' => [],
], JSON_THROW_ON_ERROR);
