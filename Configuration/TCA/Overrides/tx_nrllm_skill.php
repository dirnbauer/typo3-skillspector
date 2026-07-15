<?php

declare(strict_types=1);

defined('TYPO3') or die();

$GLOBALS['TCA']['tx_nrllm_skill']['columns'] += [
    'tx_skillspector_check_level' => [
        'label' => 'LLL:EXT:skillspector/Resources/Private/Language/locallang_db.xlf:skill.check_level',
        'config' => ['type' => 'input', 'readOnly' => true],
    ],
    'tx_skillspector_check_report' => [
        'label' => 'LLL:EXT:skillspector/Resources/Private/Language/locallang_db.xlf:skill.check_report',
        'config' => ['type' => 'text', 'rows' => 16, 'readOnly' => true],
    ],
    'tx_skillspector_checked_at' => [
        'label' => 'LLL:EXT:skillspector/Resources/Private/Language/locallang_db.xlf:skill.checked_at',
        'config' => ['type' => 'datetime', 'readOnly' => true],
    ],
];

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'tx_nrllm_skill',
    '--div--;LLL:EXT:skillspector/Resources/Private/Language/locallang_db.xlf:tab.review, tx_skillspector_check_level, tx_skillspector_checked_at, tx_skillspector_check_report'
);

