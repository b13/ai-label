<?php

declare(strict_types=1);

namespace B13\AiLabel\Service;

use B13\AiLabel\Domain\Model\AiMetadata;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;

// Builds the small action-column marker shared by the record list and file list
// event listeners: a single "AI" sparkle icon that opens a native Bootstrap
// dropdown (data-bs-toggle, no custom JS) showing whether the record is
// ai_created/ai_modified and whether it still needs review.
final class AiMetadataBadgeFactory
{
    public function __construct(
        private readonly ComponentFactory $componentFactory,
        private readonly IconFactory $iconFactory,
    ) {
    }

    public function createButton(AiMetadata $metadata, string $href): DropDownButton
    {
        $languageService = $GLOBALS['LANG'];

        $flagParts = [];
        if ($metadata->isAiCreated()) {
            $flagParts[] = $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_created');
        }
        if ($metadata->isAiModified()) {
            $flagParts[] = $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_modified');
        }
        $flagLabel = implode(', ', $flagParts);

        $dropDown = $this->componentFactory->createDropDownButton()
            ->setLabel($flagLabel)
            ->setTitle($flagLabel)
            ->setIcon($this->iconFactory->getIcon('ai-label-sparkle', IconSize::SMALL));

        $dropDown->addItem($this->componentFactory->createDropDownHeader()->setLabel($flagLabel));

        if ($metadata->isReviewed()) {
            $dropDown->addItem(
                $this->componentFactory->createDropDownItem()
                    ->setTag('a')
                    ->setHref($href)
                    ->setIcon($this->iconFactory->getIcon('actions-check', IconSize::SMALL))
                    ->setLabel(sprintf(
                        $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:recordlist.reviewedOn'),
                        date('d.m.Y', $metadata->getReviewedDate())
                    ))
            );
        } else {
            $dropDown->addItem(
                $this->componentFactory->createDropDownItem()
                    ->setTag('a')
                    ->setHref($href)
                    ->setIcon($this->iconFactory->getIcon('actions-info', IconSize::SMALL))
                    ->setLabel($languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:notice.reviewRequired'))
            );
        }

        return $dropDown;
    }
}
