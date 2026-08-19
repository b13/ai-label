<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Repository;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Repository\AiLabelDemand;
use B13\AiLabel\Domain\Repository\AiMetadataRecordFinder;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers filterAndSort()/calculateStatistics()/getDistinctTables() - the
 * overview module's filtering/sorting/stats layer added on top of
 * findFlaggedRecords(). Kept in a separate test class (not folded into
 * AiMetadataRecordFinderTest) since it needs its own fixture shaped for
 * filter/sort/stats coverage (mixed tables, origins, review states, and
 * distinguishable titles) rather than AiMetadataRecordFinderTest's
 * workspace-overlay scenario.
 */
final class AiMetadataRecordFinderDemandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'filelist',
        'fluid_styled_content',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    private AiMetadataRecordFinder $recordFinder;

    /** @var list<array{table: string, uid: int, pid: int, title: string, metadata: \B13\AiLabel\Domain\Model\AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string}> */
    private array $allRecords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetadataRecordFinder/DemandScenario.csv');

        $backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $this->recordFinder = $this->get(AiMetadataRecordFinder::class);
        $this->allRecords = $this->recordFinder->findFlaggedRecords();
    }

    #[Test]
    public function filteringByTableReturnsOnlyThatTablesRecords(): void
    {
        $demand = new AiLabelDemand(table: 'sys_file_metadata');
        $result = $this->recordFinder->filterAndSort($this->allRecords, $demand);

        self::assertSame([301], array_column($result, 'uid'));
    }

    #[Test]
    public function filteringByOriginCreatedReturnsOnlyGeneratedRecords(): void
    {
        $demand = new AiLabelDemand(origin: 'created');
        $result = $this->recordFinder->filterAndSort($this->allRecords, $demand);

        // 201 and 203 are origin=1 (Generated); 202/301 are origin=2 (Manipulated).
        self::assertSame([201, 203], $this->sortedUids($result));
    }

    #[Test]
    public function filteringByOriginModifiedReturnsOnlyManipulatedRecords(): void
    {
        $demand = new AiLabelDemand(origin: 'modified');
        $result = $this->recordFinder->filterAndSort($this->allRecords, $demand);

        self::assertSame([202, 301], $this->sortedUids($result));
    }

    #[Test]
    public function filteringByReviewStatusRequiredReturnsOnlyUnreviewedRecords(): void
    {
        $demand = new AiLabelDemand(reviewStatus: 'required');
        $result = $this->recordFinder->filterAndSort($this->allRecords, $demand);

        // 201 and 301 both have reviewed_by=0; 202/203 are already reviewed.
        self::assertSame([201, 301], $this->sortedUids($result));
    }

    #[Test]
    public function filteringByReviewStatusReviewedReturnsOnlyReviewedRecords(): void
    {
        $demand = new AiLabelDemand(reviewStatus: 'reviewed');
        $result = $this->recordFinder->filterAndSort($this->allRecords, $demand);

        self::assertSame([202, 203], $this->sortedUids($result));
    }

    #[Test]
    public function filteringBySearchMatchesTitleCaseInsensitively(): void
    {
        $demand = new AiLabelDemand(search: 'banner');
        $result = $this->recordFinder->filterAndSort($this->allRecords, $demand);

        // "Alpha banner" (201) and "Beta banner" (202) match; "Gamma text" (203) doesn't.
        self::assertSame([201, 202], $this->sortedUids($result));
    }

    #[Test]
    public function combinedFiltersAreAndedTogether(): void
    {
        $demand = new AiLabelDemand(origin: 'created', reviewStatus: 'required');
        $result = $this->recordFinder->filterAndSort($this->allRecords, $demand);

        // Only 201 is both origin=created AND review-required; 203 is created but
        // already reviewed, 301 is review-required but origin=modified.
        self::assertSame([201], array_column($result, 'uid'));
    }

    #[Test]
    public function sortingByReviewedAscendingPutsUnreviewedRecordsFirst(): void
    {
        $demand = new AiLabelDemand(orderField: 'reviewed', orderDirection: AiLabelDemand::ORDER_ASCENDING);
        $result = $this->recordFinder->filterAndSort($this->allRecords, $demand);

        // Unreviewed (201, 301) before reviewed (202, 203). usort() has been
        // stable since PHP 8.0, so within each group the pre-sort table
        // iteration order (tt_content, then sys_file_metadata) is preserved.
        self::assertSame([201, 301, 202, 203], array_column($result, 'uid'));
    }

    #[Test]
    public function sortingByTitleDescendingOrdersRecordsReverseAlphabetically(): void
    {
        $demand = new AiLabelDemand(table: 'tt_content', orderField: 'title', orderDirection: AiLabelDemand::ORDER_DESCENDING);
        $result = $this->recordFinder->filterAndSort($this->allRecords, $demand);

        // "Gamma text" > "Beta banner" > "Alpha banner"
        self::assertSame([203, 202, 201], array_column($result, 'uid'));
    }

    #[Test]
    public function calculateStatisticsCountsAcrossAllFlaggedRecordsIgnoringAnyFilter(): void
    {
        $statistics = $this->recordFinder->calculateStatistics($this->allRecords);

        self::assertSame([
            'total' => 4,
            'created' => 2,
            'modified' => 2,
            'reviewRequired' => 2,
            'reviewed' => 2,
        ], $statistics);
    }

    #[Test]
    public function getDistinctTablesReturnsSpeakingLabelsSortedAlphabeticallyByLabel(): void
    {
        // Sorted by label ("File Metadata" before "Page Content"), not by the
        // raw table name ("sys_file_metadata" would sort after "tt_content").
        self::assertSame([
            ['value' => 'sys_file_metadata', 'label' => 'File Metadata'],
            ['value' => 'tt_content', 'label' => 'Page Content'],
        ], $this->recordFinder->getDistinctTables($this->allRecords));
    }

    #[Test]
    public function buildRecordResolvesTheTablesSpeakingTitleAsTableLabel(): void
    {
        $contentRecord = $this->findRecordByUid(201);
        self::assertSame('Page Content', $contentRecord['tableLabel']);

        $fileRecord = $this->findRecordByUid(301);
        self::assertSame('File Metadata', $fileRecord['tableLabel']);
    }

    #[Test]
    public function buildRecordResolvesTheCreatingBackendUsersUsernameAsAuthor(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetadataRecordFinder/AuthorScenario.csv');
        $records = $this->recordFinder->findFlaggedRecords();

        self::assertSame('editor42', $this->findRecordByUidIn($records, 201)['author']);
        // No sys_history "add" entry exists for 202 - author must stay empty,
        // not fall back to some other user or throw.
        self::assertSame('', $this->findRecordByUidIn($records, 202)['author']);
    }

    #[Test]
    public function sortingByAuthorOrdersRecordsWithNoAuthorFirstWhenAscending(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetadataRecordFinder/AuthorScenario.csv');
        $records = $this->recordFinder->findFlaggedRecords();

        $demand = new AiLabelDemand(orderField: 'author', orderDirection: AiLabelDemand::ORDER_ASCENDING);
        $result = $this->recordFinder->filterAndSort($records, $demand);

        // '' sorts before 'editor42' - only 201 has an author, everything else is ''.
        self::assertSame(201, $result[array_key_last($result)]['uid']);
    }

    #[Test]
    public function buildRecordResolvesARenderedIconForEveryRecord(): void
    {
        foreach ($this->allRecords as $record) {
            self::assertNotSame('', $record['icon']);
        }
    }

    #[Test]
    public function sortingByTableDescendingOrdersRecordsBySpeakingLabel(): void
    {
        $demand = new AiLabelDemand(orderField: 'table', orderDirection: AiLabelDemand::ORDER_DESCENDING);
        $result = $this->recordFinder->filterAndSort($this->allRecords, $demand);

        // "Page Content" (201/202/203) sorts after "File Metadata" (301)
        // alphabetically, so descending puts the tt_content rows first.
        $tableLabels = array_column($result, 'tableLabel');
        self::assertSame(['Page Content', 'Page Content', 'Page Content', 'File Metadata'], $tableLabels);
    }

    /**
     * @return array{table: string, uid: int, pid: int, title: string, metadata: \B13\AiLabel\Domain\Model\AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string}
     */
    private function findRecordByUid(int $uid): array
    {
        return $this->findRecordByUidIn($this->allRecords, $uid);
    }

    /**
     * @param list<array{uid: int}> $records
     * @return array{table: string, uid: int, pid: int, title: string, metadata: \B13\AiLabel\Domain\Model\AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string}
     */
    private function findRecordByUidIn(array $records, int $uid): array
    {
        foreach ($records as $record) {
            if ($record['uid'] === $uid) {
                return $record;
            }
        }
        self::fail('Record with uid ' . $uid . ' not found.');
    }

    /** @param list<array{uid: int}> $records */
    private function sortedUids(array $records): array
    {
        $uids = array_column($records, 'uid');
        sort($uids);
        return $uids;
    }
}
