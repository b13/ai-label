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
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordMetadataViewHelperTest extends FunctionalTestCase
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

    #[Test]
    public function assignsAndExposesAiMetadataOfARecord(): void
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: [__DIR__ . '/Fixtures/Templates/'],
        ));
        $view->assign('record', [
            'tx_ailabel_metadata' => '{"origin":1,"reviewed_by":5,"reviewed_timestamp":1440000000}',
        ]);

        self::assertSame('1||5|1440000000', trim($view->render('RecordMetadata')));
    }

    #[Test]
    public function unflaggedRecordYieldsUnflaggedMetadata(): void
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: [__DIR__ . '/Fixtures/Templates/'],
        ));
        $view->assign('record', ['tx_ailabel_metadata' => null]);

        self::assertSame('||0|0', trim($view->render('RecordMetadata')));
    }

    #[Test]
    public function returnsAiMetadataObjectForInlineUsageWithoutAs(): void
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: [__DIR__ . '/Fixtures/Templates/'],
        ));
        $view->assign('record', [
            'tx_ailabel_metadata' => '{"origin":2,"reviewed_by":0,"reviewed_timestamp":0}',
        ]);

        self::assertSame('|1|0|0', trim($view->render('RecordMetadataInline')));
    }
}
