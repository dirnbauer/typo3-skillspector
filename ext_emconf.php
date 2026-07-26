<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Skills Inspector',
    'description' => 'Advisory security, SkillSpector, and license checks for nr_llm skills.',
    'category' => 'be',
    'state' => 'beta',
    'clearCacheOnLoad' => true,
    'author' => 'webconsulting.at',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.99.99',
            'nr_llm' => '0.25.0-0.99.99',
            'scheduler' => '14.3.0-14.99.99',
        ],
    ],
];
