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

use B13\AiLabel\Domain\Repository\AiMetadataRecordFinder;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AiMetadataRecordFinderTest extends FunctionalTestCase
{
    // ai_label composer-requires typo3/cms-filelist and typo3/cms-fluid-styled-content,
    // and BackendUtility::workspaceOL() is a no-op without EXT:workspaces loaded - none of
    // these are part of testing-framework's default sysext set, so all need to be loaded
    // explicitly.
    protected array $coreExtensionsToLoad = [
        'filelist',
        'fluid_styled_content',
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetadataRecordFinder/WorkspaceScenario.csv');

        $backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function liveWorkspaceOnlyShowsLiveRecords(): void
    {
        $this->setWorkspace(0);

        // Only the 4 live records - none of the ws1/ws2 draft, delete-placeholder or
        // new-in-workspace rows (uid 101-104) ever surface while viewing live.
        self::assertSame([1, 2, 3, 4], $this->findFlaggedUids());
    }

    #[Test]
    public function workspaceOverlaysDraftsAndAddsNewRecordsWithoutLeakingOtherWorkspaces(): void
    {
        $this->setWorkspace(1);

        $records = $this->get(AiMetadataRecordFinder::class)->findFlaggedRecords();
        $uids = array_column($records, 'uid');
        sort($uids);

        // uid 4 is missing - it's a delete-placeholder in ws1 and would disappear on
        // publish. uid 103 (ws2's draft of record 3) never appears - that's a
        // different workspace. uid 102 is new here since it only exists in ws1.
        self::assertSame([1, 2, 3, 102], $uids);

        // uid 2's content must come from its ws1 draft (uid 101), not the live row -
        // proof the overlay actually swapped content, not just kept the live uid.
        $overlaid = $this->findRecordByUid($records, 2);
        self::assertSame(5, $overlaid['metadata']->getReviewedBy());

        // uid 3 stays as its live content - the only draft of it belongs to ws2.
        $unaffected = $this->findRecordByUid($records, 3);
        self::assertSame(0, $unaffected['metadata']->getReviewedBy());
    }

    #[Test]
    public function otherWorkspaceOnlyShowsItsOwnDraftAndNoNewRecordFromWorkspaceOne(): void
    {
        $this->setWorkspace(2);

        // ws2 only has a draft of record 3 - uid 102 (new-in-ws1) and the ws1 delete
        // of record 4 must not leak into ws2's view.
        self::assertSame([1, 2, 3, 4], $this->findFlaggedUids());

        $records = $this->get(AiMetadataRecordFinder::class)->findFlaggedRecords();
        $overlaid = $this->findRecordByUid($records, 3);
        self::assertSame(9, $overlaid['metadata']->getReviewedBy());
    }

    private function setWorkspace(int $workspaceId): void
    {
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect($workspaceId));
    }

    /** @return list<int> */
    private function findFlaggedUids(): array
    {
        $uids = array_column($this->get(AiMetadataRecordFinder::class)->findFlaggedRecords(), 'uid');
        sort($uids);
        return $uids;
    }

    /**
     * @param list<array{table: string, uid: int, pid: int, title: string, metadata: \B13\AiLabel\Domain\Model\AiMetadata, reviewBadge: string}> $records
     * @return array{table: string, uid: int, pid: int, title: string, metadata: \B13\AiLabel\Domain\Model\AiMetadata, reviewBadge: string}
     */
    private function findRecordByUid(array $records, int $uid): array
    {
        foreach ($records as $record) {
            if ($record['uid'] === $uid) {
                return $record;
            }
        }
        self::fail('Record with uid ' . $uid . ' not found.');
    }
}
