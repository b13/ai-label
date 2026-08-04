<?php

declare(strict_types=1);

namespace B13\AiLabel\EventListener;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Service\AiLabelApi;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Resource\Event\AfterFileContentsSetEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

// Both events fire whenever an existing file's actual content changed outside the
// backend form (ResourceStorage::replaceFile() / File::setContents()) - same shared
// handler for both, matching core's own FlushCacheTagForFile precedent for this exact
// event combination. Other FAL events were deliberately not hooked into: AfterFileAddedEvent
// is a brand new upload (nothing stored yet to be stale), AfterFileCopiedEvent/
// AfterFileMovedEvent/AfterFileRenamedEvent don't change the file's actual content, and
// AfterFileMetaData*Event fire for tx_ailabel_metadata's own CRUD, not the file's content.
//
// A flagged file's origin/review state was set for its PREVIOUS content, so this:
// (1) nudges the editor via a flash message to re-check the origin classification,
// and (2) resets an already-reviewed file back to "review required" - the same rule
// AiMetaDataHandlerHook applies to tt_content/pages content changes, except there's
// no concurrent form submission to reconcile against here, so no "reviewed wins" case.
//
// Backend-only: neither event carries a request, and a CLI/frontend-triggered change
// (e.g. a scheduler task) has no editor to notify or attribute the review-reset to.
#[AsEventListener(identifier: 'ai-label/after-file-replaced', event: AfterFileReplacedEvent::class)]
#[AsEventListener(identifier: 'ai-label/after-file-contents-set', event: AfterFileContentsSetEvent::class)]
final class AfterFileContentChangedListener
{
    public function __construct(
        private readonly FlashMessageService $flashMessageService,
        private readonly AiLabelApi $aiLabelApi,
    ) {
    }

    public function __invoke(AfterFileReplacedEvent|AfterFileContentsSetEvent $event): void
    {
        $file = $event->getFile();
        if (!$file instanceof File) {
            return;
        }

        $metaDataRow = $file->getMetaData()->get();
        $metadata = AiMetadata::fromJsonString($metaDataRow['tx_ailabel_metadata'] ?? null);
        if (!$metadata->isFlagged()) {
            return;
        }

        $this->resetReviewIfNeeded($metadata, (int)$metaDataRow['uid']);
        if (!$this->isBackendRequest()) {
            return;
        }
        $this->addFlashMessage();
    }

    private function resetReviewIfNeeded(AiMetadata $metadata, int $metadataUid): void
    {
        if (!$metadata->isReviewed()) {
            return;
        }

        $this->aiLabelApi->aiMetadataUpdate(
            'sys_file_metadata',
            $metadataUid,
            $metadata->withReviewedBy(0)->withReviewedTimestamp(0),
            $this->getBackendUserAuth()
        );
    }

    protected function getServerRequest(): ?ServerRequestInterface
    {
        return $GLOBALS['TYPO3_REQUEST'] ?? null;
    }

    protected function getBackendUserAuth(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }

    private function isBackendRequest(): bool
    {
        $request = $this->getServerRequest();
        if ($request instanceof ServerRequestInterface === false) {
            return false;
        }
        return ApplicationType::fromRequest($request)->isBackend();
    }

    private function addFlashMessage(): void
    {
        // The Filelist "replace file" action goes through ResourceController::
        // replaceResourceAction() (an AJAX endpoint) - right after dispatching
        // AfterFileReplacedEvent, that same method drains the *default* flash
        // message queue (getMessageQueueByIdentifier() with no identifier) and
        // repackages everything it finds there into a single new message of its
        // own, titled from its own "ajax.success" label and defaulting to OK/
        // "success" severity - our message would get glued onto its "File X was
        // replaced with Y" text and lose its own title/severity. NOTIFICATION_QUEUE
        // is a separate queue core doesn't touch there; ModuleTemplate renders it
        // as its own toast (@typo3/backend/notification.js) on the next full
        // backend page render, with our title/severity intact.
        $languageService = $this->getLanguageService();
        $flashMessage = new FlashMessage(
            $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:notice.fileContentChanged'),
            $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:notice.fileContentChanged.title'),
            ContextualFeedbackSeverity::WARNING,
            true
        );
        $this->flashMessageService->getMessageQueueByIdentifier(FlashMessageQueue::NOTIFICATION_QUEUE)->enqueue($flashMessage);
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
