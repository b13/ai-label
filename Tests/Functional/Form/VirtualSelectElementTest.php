<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Form;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Form\Element\VirtualSelectElement;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

// VirtualSelectElement is the "ai_origin" TCA type=user/renderType=aiLabelVirtualSelect
// counterpart to VirtualCheckboxElement - it delegates to core's SelectSingleElement
// rendering. TcaSelectItems (the core provider that normally resolves 'items') only
// runs for config.type === 'select', so it never touches our type=user field - this
// proves the plain ['label' => ..., 'value' => ...] item shape AddAiMetaFieldsToTca
// provides directly is exactly what SelectSingleElement::render() needs, with no
// resolution step in between.
final class VirtualSelectElementTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'filelist',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function rendersASelectWithTheCurrentOriginMarkedAsSelected(): void
    {
        $element = $this->get(VirtualSelectElement::class);
        $element->setData([
            'tableName' => 'tt_content',
            'fieldName' => 'ai_origin',
            'databaseRow' => ['uid' => 1],
            'processedTca' => ['columns' => ['ai_origin' => $GLOBALS['TCA']['tt_content']['columns']['ai_origin']]],
            'parameterArray' => [
                'fieldConf' => $GLOBALS['TCA']['tt_content']['columns']['ai_origin'],
                'itemFormElValue' => 2,
                'itemFormElName' => 'data[tt_content][1][ai_origin]',
            ],
        ]);

        $result = $element->render();

        self::assertStringContainsString('<select', $result['html']);
        self::assertStringContainsString('name="data[tt_content][1][ai_origin]"', $result['html']);
        self::assertMatchesRegularExpression('/<option value="2"[^>]*selected="selected"/', $result['html']);
        self::assertDoesNotMatchRegularExpression('/<option value="0"[^>]*selected="selected"/', $result['html']);
        self::assertDoesNotMatchRegularExpression('/<option value="1"[^>]*selected="selected"/', $result['html']);
    }
}
