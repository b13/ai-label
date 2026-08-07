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

// VirtualSelectElement is the "tx_ailabel_origin" TCA
// type=user/renderType=aiLabelVirtualSelect counterpart to VirtualCheckboxElement -
// it delegates to core's SelectSingleElement rendering. TcaSelectItems (the core
// provider that normally resolves 'items') only runs for config.type === 'select', so
// it never touches our type=user field - this proves the plain
// ['label' => ..., 'value' => ...] item shape AddAiMetaFieldsToTca provides directly
// is exactly what SelectSingleElement::render() needs, with no resolution step in
// between.
final class VirtualSelectElementTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'filelist',
        'fluid_styled_content',
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
            'fieldName' => 'tx_ailabel_origin',
            'databaseRow' => ['uid' => 1],
            'inlineStructure' => [],
            'processedTca' => ['columns' => ['tx_ailabel_origin' => $GLOBALS['TCA']['tt_content']['columns']['tx_ailabel_origin']]],
            'parameterArray' => [
                'fieldConf' => $GLOBALS['TCA']['tt_content']['columns']['tx_ailabel_origin'],
                'itemFormElValue' => 2,
                'itemFormElName' => 'data[tt_content][1][tx_ailabel_origin]',
            ],
        ]);

        $result = $element->render();

        self::assertStringContainsString('<select', $result['html']);
        self::assertStringContainsString('name="data[tt_content][1][tx_ailabel_origin]"', $result['html']);
        self::assertMatchesRegularExpression('/<option value="2"[^>]*selected="selected"/', $result['html']);
        self::assertDoesNotMatchRegularExpression('/<option value="0"[^>]*selected="selected"/', $result['html']);
        self::assertDoesNotMatchRegularExpression('/<option value="1"[^>]*selected="selected"/', $result['html']);

        // The items' LLL:... labels must be resolved to actual text - TcaSelectItems
        // (the core provider that normally does this) never runs for our type=user
        // field, see the class docblock. (The field's own top-level label is a
        // separate, generic resolution step this minimal test doesn't reproduce - not
        // what's under test here, so only the <option> texts themselves are checked.)
        self::assertStringContainsString('>No AI involvement<', $result['html']);
        self::assertStringContainsString('>AI created<', $result['html']);
        self::assertStringContainsString('>AI modified<', $result['html']);
    }
}
