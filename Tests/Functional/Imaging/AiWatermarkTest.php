<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Imaging;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Imaging\AiWatermark;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AiWatermarkTest extends FunctionalTestCase
{
    // ai_label composer-requires typo3/cms-filelist and typo3/cms-fluid-styled-content -
    // neither is part of testing-framework's default sysext set, so both must be loaded
    // explicitly or PackageCollection throws.
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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Images.csv');
        // Both fixture images are the same picture - the point of the pair is the AI flag
        // on their metadata, not their content.
        copy(__DIR__ . '/Fixtures/flagged.jpg', Environment::getPublicPath() . '/fileadmin/flagged.jpg');
        copy(__DIR__ . '/Fixtures/flagged.jpg', Environment::getPublicPath() . '/fileadmin/plain.jpg');
        // appliesTo() refuses to mark anything unless a usable processor is configured;
        // the tests that assert the positive case need this to be the case.
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_enabled'] = true;
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor'] = 'ImageMagick';
    }

    /**
     * The regression this guards: the badge width used to be derived from the image width,
     * so the marker grew with the picture instead of staying a constant, discreet mark.
     */
    public static function badgeWidthDataProvider(): array
    {
        return [
            'tiny image shrinks the badge with it' => [200, 50],
            'small image shrinks the badge with it' => [400, 100],
            'medium image reaches the constant width' => [800, 160],
            'large image stays at the constant width' => [1600, 160],
            'very large image stays at the constant width' => [3000, 160],
        ];
    }

    #[Test]
    #[DataProvider('badgeWidthDataProvider')]
    public function badgeIsNeverWiderThanItsConstantTargetWidth(int $imageWidth, int $expectedBadgeWidth): void
    {
        self::assertSame($expectedBadgeWidth, $this->get(AiWatermark::class)->getBadgeWidth($imageWidth));
    }

    #[Test]
    public function badgeNeverGrowsAsTheImageGrows(): void
    {
        $watermark = $this->get(AiWatermark::class);
        $previous = 0;
        foreach ([160, 320, 640, 1280, 2560, 5120] as $imageWidth) {
            $badgeWidth = $watermark->getBadgeWidth($imageWidth);
            self::assertGreaterThanOrEqual($previous, $badgeWidth);
            self::assertLessThanOrEqual(160, $badgeWidth);
            $previous = $badgeWidth;
        }
        // ...and it has settled on the constant width well before the largest size.
        self::assertSame(160, $watermark->getBadgeWidth(5120));
    }

    #[Test]
    public function flaggedImageIsMarked(): void
    {
        $file = $this->get(ResourceFactory::class)->getFileObject(1);
        self::assertTrue($this->get(AiWatermark::class)->appliesTo($file));
    }

    #[Test]
    public function unflaggedImageIsNotMarked(): void
    {
        $file = $this->get(ResourceFactory::class)->getFileObject(2);
        self::assertFalse($this->get(AiWatermark::class)->appliesTo($file));
    }

    #[Test]
    public function svgIsNotMarked(): void
    {
        // Flagged, but never rasterised by core's SvgImageProcessor, so there are no
        // pixels to write a badge into.
        $file = $this->get(ResourceFactory::class)->getFileObject(3);
        self::assertFalse($this->get(AiWatermark::class)->appliesTo($file));
    }

    #[Test]
    public function nothingIsMarkedWhileImageProcessingIsDisabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_enabled'] = false;
        $file = $this->get(ResourceFactory::class)->getFileObject(1);
        self::assertFalse($this->get(AiWatermark::class)->appliesTo($file));
    }

    #[Test]
    public function nothingIsMarkedOnGraphicsMagick(): void
    {
        // GraphicsMagick's "convert" has no -composite operator, so those sites keep the
        // content element marker instead of a baked one.
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor'] = 'GraphicsMagick';
        $file = $this->get(ResourceFactory::class)->getFileObject(1);
        self::assertFalse($this->get(AiWatermark::class)->appliesTo($file));
    }

    #[Test]
    public function markingNeverModifiesTheOriginalFile(): void
    {
        $originalPath = Environment::getPublicPath() . '/fileadmin/flagged.jpg';
        $sha1Before = sha1_file($originalPath);

        $file = $this->get(ResourceFactory::class)->getFileObject(1);
        $processedFile = $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, ['width' => 400]);
        // Force the processing to actually have happened before comparing.
        $processedFile->getIdentifier();

        self::assertSame($sha1Before, sha1_file($originalPath));
    }
}
