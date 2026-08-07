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
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers Resources/Private/Partials/AiLabel.html directly (icon selection,
 * variant argument, asset.css inclusion) - the part of the automatic frontend
 * integration that can be verified without a full site/TypoScript-template
 * setup. The Layouts/Default.html Footer-section wiring itself (via
 * Configuration/TypoScript/setup.typoscript, registered through
 * addTypoScriptSetup() in ext_localconf.php) relies on fluid_styled_content's
 * own lib.contentElement rendering and is documented, not covered here - see
 * README.md.
 */
final class AiLabelPartialTest extends FunctionalTestCase
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

    private function renderPartial(array $data, ?string $variant = null): string
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: [__DIR__ . '/Fixtures/Templates/'],
            partialRootPaths: [__DIR__ . '/../../../Resources/Private/Partials/'],
        ));
        $view->assign('data', $data);
        $view->assign('variant', $variant);

        return trim($view->render('RenderAiLabel'));
    }

    #[Test]
    public function rendersGeneratedIconWithDefaultVariant(): void
    {
        $output = $this->renderPartial(['tx_ailabel_metadata' => '{"origin":1,"reviewed_by":0,"reviewed_timestamp":0}']);

        self::assertStringContainsString('class="b_ai-label"', $output);
        self::assertStringContainsString('ai_generated_black.svg', $output);
    }

    #[Test]
    public function rendersModifiedIcon(): void
    {
        $output = $this->renderPartial(['tx_ailabel_metadata' => '{"origin":2,"reviewed_by":0,"reviewed_timestamp":0}']);

        self::assertStringContainsString('class="b_ai-label"', $output);
        self::assertStringContainsString('ai_modified_black.svg', $output);
    }

    #[Test]
    public function respectsVariantArgument(): void
    {
        $output = $this->renderPartial(
            ['tx_ailabel_metadata' => '{"origin":1,"reviewed_by":0,"reviewed_timestamp":0}'],
            'white_transparent',
        );

        self::assertStringContainsString('ai_generated_white_transparent.svg', $output);
    }

    #[Test]
    public function unflaggedRecordRendersNothing(): void
    {
        $output = $this->renderPartial(['tx_ailabel_metadata' => null]);

        self::assertSame('', $output);
    }
}
