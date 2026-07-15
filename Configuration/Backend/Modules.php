<?php

declare(strict_types=1);

use Webconsulting\Skillspector\Controller\Backend\SkillspectorController;

return [
    'skillspector' => [
        'parent' => 'system',
        'position' => ['after' => 'nrllm'],
        'access' => 'admin',
        'path' => '/module/system/skillspector',
        'iconIdentifier' => 'skillspector-module',
        'labels' => 'LLL:EXT:skillspector/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => ['target' => SkillspectorController::class . '::handleRequest'],
        ],
    ],
];

