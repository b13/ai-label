<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Load the current ai_created / ai_modified values from the meta table into the edit form,
// since these TCA fields have no real column on the origin table.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord']
    [\B13\AiLabel\Form\FormDataProvider\EnrichAiMetaData::class] = [
        'depends' => [\TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseEditRow::class],
    ];

// Redirect ai_created / ai_modified / reviewed from DataHandler into the meta table instead of the origin table.
// No PSR-14 replacement exists for this yet, this hook is still the supported extension point.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = \B13\AiLabel\Hooks\AiMetaDataHandlerHook::class;

// Renders the "Review required" notice next to the reviewed checkbox.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1732880000] = [
    'nodeName' => 'aiLabelReviewRequiredNotice',
    'priority' => 40,
    'class' => \B13\AiLabel\Form\Element\ReviewRequiredNotice::class,
];
