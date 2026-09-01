<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Frontend;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers Resources/Private/Partials/Media/Rendering/Image, this extension's override of
 * EXT:fluid_styled_content's own image partial, which is what the "overlay" image marker
 * mode renders through.
 *
 * Rendering it directly is the only way to cover this: reaching it through a real site
 * would need fluid_styled_content to actually be the thing rendering the images, and most
 * sitepackages replace that (see README.md, "Marking images themselves"). The partial is
 * shipped twice, as Image.html and Image.fluid.html, because v13 and v14 differ only in
 * the file name convention - Fluid resolves whichever of the two fits the version in use,
 * so this test exercises the same file the site would.
 */
final class ImageOverlayPartialTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/OverlayImages.csv');
        // Same picture behind all three rows - what differs is the AI flag/review
        // state on their metadata.
        copy(__DIR__ . '/Fixtures/overlay.jpg', Environment::getPublicPath() . '/fileadmin/overlay-flagged.jpg');
        copy(__DIR__ . '/Fixtures/overlay.jpg', Environment::getPublicPath() . '/fileadmin/overlay-plain.jpg');
        copy(__DIR__ . '/Fixtures/overlay.jpg', Environment::getPublicPath() . '/fileadmin/overlay-reviewed.jpg');
    }

    private function renderImage(int $fileUid, string $mode): string
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['imageMarker'] = $mode;

        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: [__DIR__ . '/Fixtures/Templates/'],
            partialRootPaths: [__DIR__ . '/../../../Resources/Private/Partials/'],
        ));
        // "crop" has to be present explicitly: f:media reads it off the reference, and
        // getProperty() throws for keys that exist on neither the reference nor the file.
        $view->assign('file', new FileReference(['uid_local' => $fileUid, 'crop' => null]));
        $view->assign('dimensions', ['width' => 200, 'height' => 150]);
        $view->assign('settings', ['media' => ['lazyLoading' => 'lazy', 'imageDecoding' => 'async']]);

        return trim($view->render('RenderMediaImage'));
    }

    #[Test]
    public function flaggedImageIsWrappedAndMarkedInOverlayMode(): void
    {
        $output = $this->renderImage(1, 'overlay');

        self::assertStringContainsString('class="b_ai-label-image"', $output);
        self::assertStringContainsString('class="b_ai-label"', $output);
        self::assertStringContainsString('ai_generated_black.svg', $output);
        // Core's own markup still has to come through untouched.
        self::assertStringContainsString('image-embed-item', $output);
    }

    /**
     * Unlike text, a human review never lifts the disclosure duty for images
     * (EU AI Act Article 50(4)) - the marker has to stay regardless of review
     * status. Regression test for the bug fixed after 98fc775, which hid the
     * marker for reviewed files too, not just reviewed text records.
     */
    #[Test]
    public function flaggedAndReviewedImageStaysMarkedInOverlayMode(): void
    {
        $output = $this->renderImage(3, 'overlay');

        self::assertStringContainsString('class="b_ai-label"', $output);
        self::assertStringContainsString('ai_generated_black.svg', $output);
    }

    #[Test]
    public function unflaggedImageIsLeftAloneInOverlayMode(): void
    {
        $output = $this->renderImage(2, 'overlay');

        self::assertStringNotContainsString('b_ai-label', $output);
        self::assertStringContainsString('image-embed-item', $output);
    }

    /**
     * "off" is the default, and the whole point of it is that the extension renders exactly
     * what Core would have rendered.
     */
    #[Test]
    public function flaggedImageIsLeftAloneWhileTheMarkerIsOff(): void
    {
        $output = $this->renderImage(1, 'off');

        self::assertStringNotContainsString('b_ai-label', $output);
        self::assertStringContainsString('image-embed-item', $output);
    }

    /**
     * In "baked" mode the marker is already in the pixels of the processed image, so
     * wrapping it in the overlay markup as well would show it twice.
     */
    #[Test]
    public function flaggedImageIsNotWrappedInBakedMode(): void
    {
        $output = $this->renderImage(1, 'baked');

        self::assertStringNotContainsString('b_ai-label', $output);
        self::assertStringContainsString('image-embed-item', $output);
    }
}
