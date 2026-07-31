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

final class FileMetadataViewHelperTest extends FunctionalTestCase
{
    // ai_label composer-requires typo3/cms-filelist - not part of testing-framework's
    // default sysext set, so it must be loaded explicitly or PackageCollection throws.
    protected array $coreExtensionsToLoad = [
        'filelist',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/FlaggedFile.csv');
    }

    #[Test]
    public function assignsAndExposesAiMetadataOfAFileReference(): void
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: [__DIR__ . '/Fixtures/Templates/'],
        ));
        $view->assign('file', new FileReference(['uid_local' => 1]));

        self::assertSame('1||0|0', trim($view->render('FileMetadata')));
    }
}
