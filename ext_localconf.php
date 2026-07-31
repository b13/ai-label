<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Decode ai_created / ai_modified / reviewed from the ai_metadata JSON column into the
// edit form, since these TCA checkboxes have no real column of their own.
// Must also depend on TcaJson: on a new record, DatabaseRowDefaultValues forces
// ai_metadata to '' (no TCA default set), and only TcaJson (for command=new) turns
// that back into an array - without this second dependency, core's own provider
// order between TcaJson and this class is unspecified.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord']
    [\B13\AiLabel\Form\FormDataProvider\EnrichAiMetaData::class] = [
        'depends' => [
            \TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseEditRow::class,
            \TYPO3\CMS\Backend\Form\FormDataProvider\TcaJson::class,
        ],
    ];

// Fold ai_created / ai_modified / reviewed into the ai_metadata JSON column instead of
// writing them to their own (non-existent) columns. No PSR-14 replacement exists for
// this yet, this hook is still the supported extension point.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = \B13\AiLabel\Hooks\AiMetaDataHandlerHook::class;

// Renders ai_created / ai_modified / reviewed as normal checkboxToggle fields, without
// TYPO3 auto-creating a real database column for them (that only happens for type=check).
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1732880001] = [
    'nodeName' => 'aiLabelVirtualCheckbox',
    'priority' => 40,
    'class' => \B13\AiLabel\Form\Element\VirtualCheckboxElement::class,
];
