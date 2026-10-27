<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\ViewHelpers;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class WatermarkViewHelperTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'filelist',
        'fluid_styled_content',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    // tx_ailabel_watermark only exists in "baked" mode
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'ai_label' => [
                'imageMarker' => 'baked',
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/WatermarkImages.csv');
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_enabled'] = true;
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor'] = 'ImageMagick';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['imageMarker'] = 'baked';
    }

    /**
     * Output: [baked|color|position|width|variant]
     */
    private function renderWatermark(int $fileUid, ?string $variant = null): string
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: [__DIR__ . '/Fixtures/Templates/'],
        ));
        $view->assign('file', new FileReference(['uid_local' => $fileUid]));
        $view->assign('variant', $variant);

        return trim($view->render('Watermark'));
    }

    #[Test]
    public function fileWithoutOverrideUsesTheGlobalDefaults(): void
    {
        self::assertSame('[1|black|bottom-right|160|black]', $this->renderWatermark(1));
    }

    #[Test]
    public function globalColorIsTheVariantWhenTheTemplateAsksForNone(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkColor'] = 'white';

        self::assertSame('[1|white|bottom-right|160|white]', $this->renderWatermark(1));
    }

    #[Test]
    public function templateVariantWinsOverTheGlobalColor(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkColor'] = 'white';

        self::assertSame('[1|white|bottom-right|160|black]', $this->renderWatermark(1, 'black'));
    }

    #[Test]
    public function perFileColorWinsOverTheTemplateVariant(): void
    {
        self::assertSame('[1|white|bottom-right|160|white]', $this->renderWatermark(2, 'black'));
    }

    #[Test]
    public function perFileColorKeepsTheTransparencyOfTheVariant(): void
    {
        self::assertSame('[1|white|bottom-right|160|white_transparent]', $this->renderWatermark(2, 'black_transparent'));
    }

    #[Test]
    public function projectVariantIsKept(): void
    {
        self::assertSame('[1|white|bottom-right|160|brand]', $this->renderWatermark(2, 'brand'));
    }

    #[Test]
    public function perFilePositionAndWidthOverrideTheGlobalDefaults(): void
    {
        self::assertSame('[1|black|top-left|80|black]', $this->renderWatermark(3));
    }

    #[Test]
    public function isNotBakedOutsideBakedMode(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['imageMarker'] = 'overlay';

        self::assertSame('[|white|bottom-right|160|white]', $this->renderWatermark(2));
    }
}
