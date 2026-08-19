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
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AiLabelApiTest extends FunctionalTestCase
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

    protected ?BackendUserAuthentication $backendUser = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
    }

    #[Test]
    public function isReachableViaMakeInstanceForOptionalIntegrationsOutsideThisExtensionsOwnDiGraph(): void
    {
        // Regression test: this service must be public. An optional integration
        // living outside ai_label's own DI graph (e.g. aim's AiLabelMiddleware)
        // can only ever reach it via GeneralUtility::makeInstance(), never
        // constructor injection. If the service isn't public, the container
        // silently falls through to a bare `new AiLabelApi()` missing all 3
        // constructor args, which throws ArgumentCountError here instead of
        // returning a working instance - previously the actual, silently
        // swallowed cause of a real bug (see git history).
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiLabelApi/FlaggedAndReviewedRecord.csv');

        GeneralUtility::makeInstance(AiLabelApi::class)->aiModified('tt_content', 1, $this->backendUser);

        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiLabelApi/AiModifiedResetsReviewResult.csv');
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
