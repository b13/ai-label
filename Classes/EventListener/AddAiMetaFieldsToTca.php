<?php

declare(strict_types=1);

namespace B13\AiLabel\EventListener;

use B13\AiLabel\Event\ModifyExcludedTablesEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Configuration\Event\AfterTcaCompilationEvent;

// Adds the "ai_created" and "ai_modified" checkboxes to every editable table's TCA.
// The fields are not backed by a real database column on the origin table -
// their values are redirected into tx_ailabel_domain_model_meta by AiMetaDataHandlerHook.
#[AsEventListener(identifier: 'ai-label/add-ai-meta-fields-to-tca')]
final class AddAiMetaFieldsToTca
{
    // Tables that never get the fields, e.g. system tables or our own storage table.
    // Extend or shrink this list by listening to ModifyExcludedTablesEvent.
    private const DEFAULT_EXCLUDED_TABLES = [
        'tx_ailabel_domain_model_meta',
        'be_users',
        'be_groups',
        'sys_log',
        'sys_history',
        'sys_redirect',
        'sys_registry',
    ];

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function __invoke(AfterTcaCompilationEvent $event): void
    {
        $tca = $event->getTca();

        /** @var ModifyExcludedTablesEvent $excludeEvent */
        $excludeEvent = $this->eventDispatcher->dispatch(
            new ModifyExcludedTablesEvent(self::DEFAULT_EXCLUDED_TABLES)
        );
        $excludedTables = $excludeEvent->getExcludedTables();

        foreach ($tca as $tableName => $tableConfig) {
            if (in_array($tableName, $excludedTables, true)) {
                continue;
            }
            // Skip tables without a proper record definition, e.g. plain MM tables
            if (empty($tableConfig['columns']) || empty($tableConfig['ctrl']['title'] ?? '')) {
                continue;
            }

            $tca[$tableName]['columns']['ai_created'] = [
                'label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_created',
                'onChange' => 'reload',
                'config' => [
                    'type' => 'check',
                    'renderType' => 'checkboxToggle',
                    'default' => 0,
                ],
            ];
            $tca[$tableName]['columns']['ai_modified'] = [
                'label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_modified',
                'onChange' => 'reload',
                'config' => [
                    'type' => 'check',
                    'renderType' => 'checkboxToggle',
                    'default' => 0,
                ],
            ];
            // Only relevant while the record is flagged as AI-created/-modified
            $tca[$tableName]['columns']['reviewed'] = [
                'label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.reviewed',
                'config' => [
                    'type' => 'check',
                    'renderType' => 'checkboxToggle',
                    'default' => 0,
                ],
                'displayCond' => [
                    'OR' => [
                        'FIELD:ai_created:=:1',
                        'FIELD:ai_modified:=:1',
                    ],
                ],
            ];
            // Pure notice, not persisted - shown next to "reviewed" while it is unset
            $tca[$tableName]['columns']['ai_review_required_notice'] = [
                'label' => '',
                'config' => [
                    'type' => 'user',
                    'renderType' => 'aiLabelReviewRequiredNotice',
                ],
                'displayCond' => [
                    'AND' => [
                        'FIELD:reviewed:=:0',
                        [
                            'OR' => [
                                'FIELD:ai_created:=:1',
                                'FIELD:ai_modified:=:1',
                            ],
                        ],
                    ],
                ],
            ];
            $tca[$tableName]['palettes']['aiLabelMetadata'] = [
                'showitem' => 'ai_created, ai_modified, --linebreak--, reviewed, ai_review_required_notice',
            ];

            foreach ($tableConfig['types'] ?? [] as $typeKey => $typeConfig) {
                $tca[$tableName]['types'][$typeKey]['showitem'] = rtrim($typeConfig['showitem'] ?? '', ', ')
                    . ', --div--;LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:tabs.aiMetadata'
                    . ', --palette--;;aiLabelMetadata';
            }
        }

        $event->setTca($tca);
    }
}
