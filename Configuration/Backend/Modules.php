<?php

use B13\AiLabel\Controller\AiLabelOverviewController;

/**
 * Definitions for modules provided by EXT:ai_label
 */
return [
    'web_ai_label_overview' => [
        'parent' => 'web',
        'position' => [],
        'access' => 'user',
        'workspaces' => 'live',
        'iconIdentifier' => 'module-ailabel',
        'path' => '/module/web/ai-label-overview',
        'labels' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_mod.xlf',
        'inheritNavigationComponentFromMainModule' => false,
        'routes' => [
            '_default' => [
                'target' => AiLabelOverviewController::class . '::handleRequest',
            ],
        ],
    ],
];
