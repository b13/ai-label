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

use B13\AiLabel\Domain\Enum\WatermarkWidth;
use B13\AiLabel\Imaging\AiWatermark;
use B13\AiLabel\Imaging\ProcessedFileInvalidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
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

    // Needed for AiWatermarkProcessor::canProcessTask() to engage in the full
    // $file->process() tests below - mode is read at bootstrap, too early for a
    // $GLOBALS override in a test method. Safe for the other tests, which don't
    // depend on the mode.
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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Images.csv');
        // Both fixture images are the same picture - the point of the pair is the AI flag
        // on their metadata, not their content.
        copy(__DIR__ . '/Fixtures/flagged.jpg', Environment::getPublicPath() . '/fileadmin/flagged.jpg');
        copy(__DIR__ . '/Fixtures/flagged.jpg', Environment::getPublicPath() . '/fileadmin/plain.jpg');
        // Only the DB is truncated between methods, not the filesystem - a stale
        // processed variant would otherwise get double-watermarked.
        GeneralUtility::rmdir(Environment::getPublicPath() . '/fileadmin/_processed_', true);
        // appliesTo() refuses to mark anything unless a usable processor is configured;
        // the tests that assert the positive case need this to be the case.
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_enabled'] = true;
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor'] = 'ImageMagick';
        // core's default processor_path doesn't hold on every machine (e.g. Homebrew).
        $convertPath = trim((string)shell_exec('command -v convert'));
        if ($convertPath !== '') {
            $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path'] = dirname($convertPath) . '/';
            $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path_lzw'] = dirname($convertPath) . '/';
        }
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

    // Downscales the region to 4x4 and averages it - smooths text/background pixels
    // into one brightness figure, robust to the exact glyph layout.
    private function averageBrightness(string $filePath, int $x, int $y, int $width, int $height): float
    {
        $source = imagecreatefromstring((string)file_get_contents($filePath));
        $thumbnail = imagecreatetruecolor(4, 4);
        imagecopyresampled($thumbnail, $source, 0, 0, $x, $y, 4, 4, $width, $height);

        $total = 0.0;
        for ($thumbnailY = 0; $thumbnailY < 4; $thumbnailY++) {
            for ($thumbnailX = 0; $thumbnailX < 4; $thumbnailX++) {
                $colors = imagecolorsforindex($thumbnail, imagecolorat($thumbnail, $thumbnailX, $thumbnailY));
                $total += ($colors['red'] + $colors['green'] + $colors['blue']) / 3;
            }
        }

        // imagedestroy() is a no-op (deprecated) since PHP 8.0 - GD frees these itself.
        return $total / 16;
    }

    // Tightly interior to the "ai_generated" badge's bounding box on a 600px-wide
    // variant (badge width 150, margin 18, height ~29) - avoids diluting the
    // average with the surrounding, untouched photo.
    private function topLeftCornerBrightness(string $filePath): float
    {
        return $this->averageBrightness($filePath, 30, 22, 125, 18);
    }

    private function bottomRightCornerBrightness(string $filePath): float
    {
        return $this->averageBrightness($filePath, 445, 408, 125, 17);
    }

    // Left of where the small (80px) badge's left edge sits (582 - 80 = 502, minus the
    // 18px margin already baked into that 582), but still inside the regular/capped
    // (150px) badge's own left portion - so this strip is only ever covered by the wider
    // badge. Same vertical band as bottomRightCornerBrightness(), since width alone
    // shouldn't move the badge's vertical position.
    private function outsideShrunkBadgeBrightness(string $filePath): float
    {
        return $this->averageBrightness($filePath, 445, 408, 50, 17);
    }

    private function processedFilePath(int $fileUid): string
    {
        $file = $this->get(ResourceFactory::class)->getFileObject($fileUid);
        $processedFile = $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, ['width' => 600]);
        return $processedFile->getForLocalProcessing(false);
    }

    // The fixture image is 800px wide, so asking for 800 leaves FAL nothing to scale -
    // the case a hero image rendered at its own dimensions ends up in.
    private function processedFileAtNativeWidth(int $fileUid): ProcessedFile
    {
        return $this->get(ResourceFactory::class)->getFileObject($fileUid)
            ->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, ['width' => 800]);
    }

    /**
     * An image that needs no scaling gets a processed file that "uses the original file":
     * one row, pointing at the editor's own asset. Invalidation must drop that row, or
     * AbstractTask::fileNeedsProcessing() keeps returning false, AiWatermarkProcessor is
     * never called again, and the file is served unmarked for good - which is what
     * happens to every image that was already rendered before it was flagged, or before
     * "baked" was switched on.
     */
    #[Test]
    public function aVariantRenderedBeforeTheModeWasEnabledIsReplacedByAMarkedOne(): void
    {
        // 1. Rendered while the marker mode is still off.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['imageMarker'] = 'off';
        self::assertTrue(
            $this->processedFileAtNativeWidth(1)->usesOriginalFile(),
            'sanity: an image that needs no scaling is served straight from the original'
        );

        // 2. "baked" is switched on and the variants are flushed.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['imageMarker'] = 'baked';
        $this->get(ProcessedFileInvalidator::class)->invalidateForAllFlaggedFiles();

        // 3. The next render is a real processed file carrying the badge. Both fixture
        //    files hold the same picture, so the flagged one differing means a badge.
        $marked = $this->processedFileAtNativeWidth(1);
        self::assertFalse($marked->usesOriginalFile());
        self::assertNotSame(
            sha1_file($this->processedFileAtNativeWidth(2)->getForLocalProcessing(false)),
            sha1_file($marked->getForLocalProcessing(false))
        );
    }

    #[Test]
    public function badgeWidthUsesTheConfiguredGlobalWidth(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkWidth'] = '80';
        self::assertSame(80, $this->get(AiWatermark::class)->getBadgeWidth(5120));
    }

    #[Test]
    public function badgeWidthPerFileOverrideBeatsTheGlobalDefault(): void
    {
        self::assertSame(80, $this->get(AiWatermark::class)->getBadgeWidth(5120, WatermarkWidth::Small));
    }

    #[Test]
    public function badgeShrinksToTheConfiguredGlobalWidth(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkWidth'] = '80';

        $flagged = $this->processedFilePath(1);
        $plain = $this->processedFilePath(2);

        // Still inside the shrunk badge's own footprint, near the bottom-right corner -
        // confirms a badge was actually rendered, just a narrower one.
        self::assertLessThan(
            $this->bottomRightCornerBrightness($plain) - 5,
            $this->bottomRightCornerBrightness($flagged)
        );
        // A strip the regular-width badge would have covered, but the shrunk one
        // doesn't reach - stays untouched once the badge is actually narrower.
        self::assertEqualsWithDelta(
            $this->outsideShrunkBadgeBrightness($plain),
            $this->outsideShrunkBadgeBrightness($flagged),
            2.0,
            'The area outside the shrunk badge should stay untouched.'
        );
    }

    #[Test]
    public function badgeDefaultsToBottomRightAndBlackWhenNothingIsConfigured(): void
    {
        $flagged = $this->processedFilePath(1);
        $plain = $this->processedFilePath(2);

        // A black badge darkens the (originally light tan) bottom-right corner.
        self::assertLessThan(
            $this->bottomRightCornerBrightness($plain) - 5,
            $this->bottomRightCornerBrightness($flagged)
        );
        self::assertEqualsWithDelta(
            $this->topLeftCornerBrightness($plain),
            $this->topLeftCornerBrightness($flagged),
            2.0,
            'The top-left corner should stay untouched when the badge is bottom right.'
        );
    }

    #[Test]
    public function badgeMovesToTheConfiguredGlobalPosition(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkPosition'] = 'top-left';

        $flagged = $this->processedFilePath(1);
        $plain = $this->processedFilePath(2);

        self::assertLessThan(
            $this->topLeftCornerBrightness($plain) - 5,
            $this->topLeftCornerBrightness($flagged)
        );
        self::assertEqualsWithDelta(
            $this->bottomRightCornerBrightness($plain),
            $this->bottomRightCornerBrightness($flagged),
            2.0,
            'The bottom-right corner should stay untouched once the badge moved to top-left.'
        );
    }

    #[Test]
    public function badgeUsesTheConfiguredGlobalColor(): void
    {
        // Also moved to top-left, to observe the color against a darker background.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkPosition'] = 'top-left';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkColor'] = 'white';

        $flagged = $this->processedFilePath(1);
        $plain = $this->processedFilePath(2);

        self::assertGreaterThan(
            $this->topLeftCornerBrightness($plain) + 5,
            $this->topLeftCornerBrightness($flagged)
        );
    }

    #[Test]
    public function perFileOverrideBeatsTheGlobalDefault(): void
    {
        // Global stays default (bottom-right, black); Doctrine encodes the array
        // itself for the json column - pre-encoding it would double-encode it.
        $this->getConnectionPool()->getConnectionForTable('sys_file_metadata')->update(
            'sys_file_metadata',
            ['tx_ailabel_watermark' => ['position' => 'top-left', 'color' => 'white']],
            ['uid' => 1]
        );

        $flagged = $this->processedFilePath(1);
        $plain = $this->processedFilePath(2);

        self::assertGreaterThan(
            $this->topLeftCornerBrightness($plain) + 5,
            $this->topLeftCornerBrightness($flagged),
            'Expected a white badge at the top-left corner, per the per-file override.'
        );
        self::assertEqualsWithDelta(
            $this->bottomRightCornerBrightness($plain),
            $this->bottomRightCornerBrightness($flagged),
            2.0,
            'The bottom-right corner should stay untouched once the override moved the badge to top-left.'
        );
    }

    #[Test]
    public function missingOverrideFallsBackToTheGlobalDefault(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkPosition'] = 'top-left';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['watermarkColor'] = 'white';
        // Fixture leaves tx_ailabel_watermark NULL for uid 1 - nothing to inherit from.

        $flagged = $this->processedFilePath(1);
        $plain = $this->processedFilePath(2);

        self::assertGreaterThan(
            $this->topLeftCornerBrightness($plain) + 5,
            $this->topLeftCornerBrightness($flagged)
        );
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
