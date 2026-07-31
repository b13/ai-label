<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Service;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Service\AiLabelApi;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AiLabelApiTest extends FunctionalTestCase
{
    // ai_label composer-requires typo3/cms-filelist - not part of testing-framework's
    // default sysext set, so it must be loaded explicitly or PackageCollection throws.
    protected array $coreExtensionsToLoad = [
        'filelist',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    protected ?BackendUserAuthentication $backendUser = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
    }

    #[Test]
    public function aiModifiedResetsReviewedByOnAnAlreadyReviewedRecord(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiLabelApi/FlaggedAndReviewedRecord.csv');

        $this->get(AiLabelApi::class)->aiModified('tt_content', 1, $this->backendUser);

        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiLabelApi/AiModifiedResetsReviewResult.csv');
    }

    #[Test]
    public function aiCreatedResetsReviewedByOnAnAlreadyReviewedRecord(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiLabelApi/FlaggedAndReviewedRecord.csv');

        $this->get(AiLabelApi::class)->aiCreated('tt_content', 1, $this->backendUser);

        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiLabelApi/AiCreatedResetsReviewResult.csv');
    }

    #[Test]
    public function aiRemovedClearsTheWholeFlagIncludingReview(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiLabelApi/FlaggedAndReviewedRecord.csv');

        $this->get(AiLabelApi::class)->aiRemoved('tt_content', 1, $this->backendUser);

        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiLabelApi/AiRemovedResult.csv');
    }

    #[Test]
    public function throwsWithoutAnyBackendUserAvailable(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiLabelApi/FlaggedAndReviewedRecord.csv');
        unset($GLOBALS['BE_USER']);

        $this->expectException(\RuntimeException::class);

        $this->get(AiLabelApi::class)->aiModified('tt_content', 1);
    }

    #[Test]
    public function throwsForATableThatIsNotRegistered(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->get(AiLabelApi::class)->aiModified('be_groups', 1, $this->backendUser);
    }
}
