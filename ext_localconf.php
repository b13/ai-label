<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Decode tx_ailabel_origin / tx_ailabel_reviewed from the tx_ailabel_metadata JSON
// column into the edit form, since these virtual TCA fields have no real column of
// their own.
// Must also depend on TcaJson: on a new record, DatabaseRowDefaultValues forces
// tx_ailabel_metadata to '' (no TCA default set), and only TcaJson (for command=new)
// turns that back into an array - without this second dependency, core's own provider
// order between TcaJson and this class is unspecified.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord']
    [\B13\AiLabel\Form\FormDataProvider\EnrichAiMetaData::class] = [
        'depends' => [
            \TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseEditRow::class,
            \TYPO3\CMS\Backend\Form\FormDataProvider\TcaJson::class,
        ],
    ];

// Fold tx_ailabel_origin / tx_ailabel_reviewed into the tx_ailabel_metadata JSON
// column instead of writing them to their own (non-existent) columns. No PSR-14
// replacement exists for this yet, this hook is still the supported extension point.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = \B13\AiLabel\Hooks\AiMetaDataHandlerHook::class;

// Renders "tx_ailabel_reviewed" as a normal checkboxToggle field, without TYPO3
// auto-creating a real database column for it (that only happens for type=check).
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1732880001] = [
    'nodeName' => 'aiLabelVirtualCheckbox',
    'priority' => 40,
    'class' => \B13\AiLabel\Form\Element\VirtualCheckboxElement::class,
];

// Renders "tx_ailabel_origin" as a normal selectSingle field, without TYPO3
// auto-creating a real database column for it (that only happens for type=select).
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1785502900] = [
    'nodeName' => 'aiLabelVirtualSelect',
    'priority' => 40,
    'class' => \B13\AiLabel\Form\Element\VirtualSelectElement::class,
];
