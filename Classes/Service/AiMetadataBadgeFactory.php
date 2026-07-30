<?php

declare(strict_types=1);

namespace B13\AiLabel\Service;

use B13\AiLabel\Domain\Model\AiMetadata;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;

// Builds the small action-column marker shared by the record list and file list
// event listeners - a checkmark once reviewed, an info icon while review is
// still required, both linking straight to the record's edit form.
final class AiMetadataBadgeFactory
{
    public function __construct(
        private readonly ComponentFactory $componentFactory,
        private readonly IconFactory $iconFactory,
    ) {
    }

    public function createButton(AiMetadata $metadata, string $href): LinkButton
    {
        return $this->componentFactory->createLinkButton()
            ->setHref($href)
            ->setIcon($this->iconFactory->getIcon(
                $metadata->isReviewed() ? 'actions-check' : 'actions-info',
                IconSize::SMALL
            ))
            ->setTitle($this->buildTitle($metadata));
    }

    private function buildTitle(AiMetadata $metadata): string
    {
        $languageService = $GLOBALS['LANG'];

        $parts = [];
        if ($metadata->isAiCreated()) {
            $parts[] = $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_created');
        }
        if ($metadata->isAiModified()) {
            $parts[] = $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_modified');
        }

        $status = $metadata->isReviewed()
            ? sprintf(
                $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:recordlist.reviewedOn'),
                date('d.m.Y', $metadata->getReviewedDate())
            )
            : $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:notice.reviewRequired');

        return implode(', ', $parts) . ' - ' . $status;
    }
}
