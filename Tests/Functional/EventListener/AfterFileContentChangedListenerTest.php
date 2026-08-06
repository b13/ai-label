<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\EventListener;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\EventListener\AfterFileContentChangedListener;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Resource\Event\AfterFileContentsSetEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AfterFileContentChangedListenerTest extends FunctionalTestCase
{
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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Files.csv');
        // ResourceFactory::getFileObject() resolves the file's storage via
        // StorageRepository, which checks the current backend user's storage
        // permissions - needs a real one, not just a request. Also used by
        // AiLabelApi::aiMetadataUpdate() as the DataHandler-submitting user.
        $backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        // The flash message text is resolved via LanguageService::sL().
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function addsAFlashMessageForAFlaggedUnreviewedFileOnReplace(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->backendRequest();

        $this->dispatchReplacedEvent(1);

        $messages = $this->flashMessages();
        self::assertCount(1, $messages);
        self::assertSame('AI origin check needed', $messages[0]->getTitle());
        self::assertStringContainsString(
            'The file was replaced. Please check whether the stored AI origin is still correct.',
            $messages[0]->getMessage()
        );
        // Unreviewed already - nothing to reset, so no write should have happened.
        self::assertCSVDataSet(__DIR__ . '/Fixtures/UnchangedFlaggedUnreviewedFileResult.csv');
    }

    #[Test]
    public function addsAFlashMessageForAFlaggedUnreviewedFileOnContentsSet(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->backendRequest();

        $this->dispatchContentsSetEvent(1);

        self::assertCount(1, $this->flashMessages());
    }

    #[Test]
    public function resetsReviewOnAFlaggedAndReviewedFile(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->backendRequest();

        $this->dispatchReplacedEvent(3);

        self::assertCount(1, $this->flashMessages());
        self::assertCSVDataSet(__DIR__ . '/Fixtures/FlaggedAndReviewedFileResetResult.csv');
    }

    #[Test]
    public function addsNoFlashMessageForAnUnflaggedFile(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->backendRequest();

        $this->dispatchReplacedEvent(2);

        self::assertCount(0, $this->flashMessages());
    }

    #[Test]
    public function addsNoFlashMessageOutsideBackendContextButStillResetsReview(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);

        $this->dispatchReplacedEvent(3);

        // No editor to notify outside the backend, but the review reset itself isn't
        // gated by request context - see AfterFileContentChangedListener::__invoke().
        self::assertCount(0, $this->flashMessages());
        self::assertCSVDataSet(__DIR__ . '/Fixtures/FlaggedAndReviewedFileResetResult.csv');
    }

    #[Test]
    public function addsNoFlashMessageWithoutAnyRequestAvailable(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);

        $this->dispatchReplacedEvent(1);

        self::assertCount(0, $this->flashMessages());
    }

    private function dispatchReplacedEvent(int $fileUid): void
    {
        $file = $this->getFile($fileUid);
        $this->get(AfterFileContentChangedListener::class)->__invoke(new AfterFileReplacedEvent($file, '/tmp/whatever'));
    }

    private function dispatchContentsSetEvent(int $fileUid): void
    {
        $file = $this->getFile($fileUid);
        $this->get(AfterFileContentChangedListener::class)->__invoke(new AfterFileContentsSetEvent($file, 'new content'));
    }

    private function getFile(int $fileUid): File
    {
        $file = $this->get(ResourceFactory::class)->getFileObject($fileUid);
        self::assertInstanceOf(File::class, $file);
        return $file;
    }

    private function backendRequest(): ServerRequest
    {
        return (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    // NOTIFICATION_QUEUE, not the default queue - ResourceController::
    // replaceResourceAction() drains the default one for its own AJAX response,
    // see AfterFileContentChangedListener::addFlashMessage().
    /** @return list<\TYPO3\CMS\Core\Messaging\FlashMessage> */
    private function flashMessages(): array
    {
        return $this->get(FlashMessageService::class)
            ->getMessageQueueByIdentifier(FlashMessageQueue::NOTIFICATION_QUEUE)
            ->getAllMessagesAndFlush();
    }
}
