<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Command;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Command\FlushWatermarksCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FlushWatermarksCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'filelist',
        'fluid_styled_content',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    private const VARIANTS = [
        'generated-600.jpg',
        'human-600.jpg',
        'modified-600.jpg',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/FlaggedAndUnflaggedFiles.csv');
        $this->createVariants(self::VARIANTS);
    }

    #[Test]
    public function flushesTheVariantsOfFlaggedFilesOnlyAndLeavesUnflaggedOnesAlone(): void
    {
        $tester = new CommandTester($this->get(FlushWatermarksCommand::class));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        // Two *files*, out of five metadata rows: one unflagged, one a flagged
        // translation of file 1, one a flagged workspace version of file 3.
        self::assertStringContainsString('2 AI-flagged file(s)', $tester->getDisplay());

        // AI-created and AI-modified: both flushed, on disk and in the DB.
        self::assertFalse($this->variantExists('generated-600.jpg'));
        self::assertFalse($this->variantExists('modified-600.jpg'));
        // Origin "human", stored as {"origin": 0, ...} rather than NULL - the column is
        // set, so the query's IS NOT NULL pre-filter matches it and only isFlagged()
        // keeps it out. Its variant has to survive.
        self::assertTrue($this->variantExists('human-600.jpg'));
        self::assertSame(1, $this->countProcessedFiles());
    }

    // Reviewed files are flushed too: a review never removes the badge from an image,
    // so its variants are exactly as stale as any other flagged file's (metadata uid 3
    // in the fixture is flagged *and* reviewed).
    #[Test]
    public function aReviewedFileIsFlushedAsWell(): void
    {
        (new CommandTester($this->get(FlushWatermarksCommand::class)))->execute([]);

        self::assertFalse($this->variantExists('modified-600.jpg'));
    }

    #[Test]
    public function reportsCleanlyWhenNothingIsFlagged(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_file_metadata')
            ->executeStatement('UPDATE sys_file_metadata SET tx_ailabel_metadata = NULL');

        $tester = new CommandTester($this->get(FlushWatermarksCommand::class));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('nothing to flush', $tester->getDisplay());
        foreach (self::VARIANTS as $variant) {
            self::assertTrue($this->variantExists($variant));
        }
    }

    // A translation is a metadata record of its own with the same "file", and processed
    // files belong to the file rather than to a language - so the same file must not be
    // flushed (or counted) twice. Fixture: uid 4 is a flagged translation of uid 1.
    #[Test]
    public function aFlaggedTranslationDoesNotFlushTheSameFileTwice(): void
    {
        $translations = $this->getConnectionPool()->getConnectionForTable('sys_file_metadata')
            ->fetchOne('SELECT COUNT(*) FROM sys_file_metadata WHERE file = 1 AND tx_ailabel_metadata IS NOT NULL');
        self::assertSame(2, (int)$translations, 'sanity: file 1 is flagged through two metadata records');

        $tester = new CommandTester($this->get(FlushWatermarksCommand::class));
        $tester->execute([]);

        self::assertStringContainsString('2 AI-flagged file(s)', $tester->getDisplay());
        self::assertFalse($this->variantExists('generated-600.jpg'));
    }

    // ...and a file flagged *only* in a translation still has to be flushed: which
    // metadata record carries the flag doesn't matter, they all resolve to the same
    // variants. This adds a flagged translation of file 2, whose default-language
    // metadata is the unflagged one from the base fixture.
    #[Test]
    public function aFileFlaggedOnlyInATranslationIsFlushedToo(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/FlaggedTranslationOfAnUnflaggedFile.csv');

        $tester = new CommandTester($this->get(FlushWatermarksCommand::class));
        $tester->execute([]);

        self::assertStringContainsString('3 AI-flagged file(s)', $tester->getDisplay());
        self::assertFalse($this->variantExists('human-600.jpg'));
    }

    // sys_file_metadata is ctrl.versioningWS, so an unrestricted query also returns
    // workspace drafts - they point at the same file as their live counterpart and would
    // only flush it again. Fixture: uid 5 is a workspace version of uid 3.
    #[Test]
    public function workspaceVersionsAreIgnored(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_file_metadata')
            ->executeStatement('DELETE FROM sys_file_metadata WHERE uid IN (1, 3, 4)');

        $tester = new CommandTester($this->get(FlushWatermarksCommand::class));
        $tester->execute([]);

        // Only the unflagged live row and the flagged workspace draft are left.
        self::assertStringContainsString('nothing to flush', $tester->getDisplay());
        self::assertTrue($this->variantExists('modified-600.jpg'));
    }

    // The command has to work in every mode - switching *away* from "baked" is exactly
    // when the leftover variants still carry a burned-in badge, which "overlay" then
    // renders a second time on top.
    #[Test]
    public function flushesInOverlayModeTooAndSaysWhy(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['imageMarker'] = 'overlay';

        $tester = new CommandTester($this->get(FlushWatermarksCommand::class));
        $tester->execute([]);

        self::assertStringContainsString('currently "overlay"', $tester->getDisplay());
        self::assertFalse($this->variantExists('generated-600.jpg'));
    }

    /**
     * @param list<string> $variants
     */
    private function createVariants(array $variants): void
    {
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin/_processed_');
        foreach ($variants as $variant) {
            copy(
                __DIR__ . '/../Imaging/Fixtures/flagged.jpg',
                Environment::getPublicPath() . '/fileadmin/_processed_/' . $variant
            );
        }
    }

    private function variantExists(string $name): bool
    {
        return file_exists(Environment::getPublicPath() . '/fileadmin/_processed_/' . $name);
    }

    private function countProcessedFiles(): int
    {
        return (int)$this->getConnectionPool()
            ->getConnectionForTable('sys_file_processedfile')
            ->fetchOne('SELECT COUNT(*) FROM sys_file_processedfile');
    }
}
