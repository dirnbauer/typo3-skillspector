<?php

declare(strict_types=1);

use Webconsulting\Skillspector\Controller\Backend\SkillspectorController;

/**
 * System → Skills Inspector: advisory security and license review of the
 * skills nr_llm manages. Administrators only.
 */
return [
    'skillspector' => [
        'parent' => 'system',
        'position' => ['after' => 'nrllm'],
        'access' => 'admin',
        'path' => '/module/system/skillspector',
        'iconIdentifier' => 'skillspector-module',
        'labels' => 'skillspector.modules.inspector',
        'routes' => [
            '_default' => ['target' => SkillspectorController::class . '::handleRequest'],
        ],
    ],
];
