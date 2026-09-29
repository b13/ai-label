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

final class AltViewHelperTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AltImages.csv');
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_enabled'] = true;
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor'] = 'ImageMagick';
    }

    private function renderAlt(array $reference, string $mode = 'baked'): string
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['imageMarker'] = $mode;

        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: [__DIR__ . '/Fixtures/Templates/'],
        ));
        $view->assign('file', new FileReference($reference));

        return trim($view->render('Alt'));
    }

    #[Test]
    public function bakedGeneratedImageGetsTheLabelAppended(): void
    {
        self::assertSame('[A lighthouse (AI generated)]', $this->renderAlt(['uid_local' => 1]));
    }

    #[Test]
    public function bakedModifiedImageGetsTheModifiedLabel(): void
    {
        self::assertSame('[A lighthouse (AI modified)]', $this->renderAlt(['uid_local' => 2]));
    }

    #[Test]
    public function altOverriddenOnTheReferenceIsLabelledToo(): void
    {
        self::assertSame('[A red lighthouse (AI generated)]', $this->renderAlt(['uid_local' => 1, 'alternative' => 'A red lighthouse']));
    }

    #[Test]
    public function emptyAltBecomesTheLabelAlone(): void
    {
        self::assertSame('[(AI generated)]', $this->renderAlt(['uid_local' => 4]));
    }

    #[Test]
    public function labelIsNotAppendedTwice(): void
    {
        self::assertSame('[A lighthouse (AI generated)]', $this->renderAlt(['uid_local' => 1, 'alternative' => 'A lighthouse (AI generated)']));
    }

    #[Test]
    public function unflaggedImageKeepsItsAlt(): void
    {
        self::assertSame('[A lighthouse]', $this->renderAlt(['uid_local' => 3]));
    }

    #[Test]
    public function altIsLeftAloneOutsideBakedMode(): void
    {
        self::assertSame('[A lighthouse]', $this->renderAlt(['uid_local' => 1], 'overlay'));
        self::assertSame('[A lighthouse]', $this->renderAlt(['uid_local' => 1], 'off'));
    }
}
