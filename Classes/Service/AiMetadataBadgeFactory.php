<?php

declare(strict_types=1);

namespace B13\AiLabel\Service;

use B13\AiLabel\Domain\Model\AiMetadata;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// TYPO3 v14+ only - ComponentFactory/DropDownButton don't exist on v13.
// See Classes/Legacy/Service/AiMetadataBadgeFactory.php for the v13 equivalent.
//
// Builds the small action-column marker shared by the record list and file list
// event listeners: a single "AI" sparkle icon that opens a native Bootstrap
// dropdown (data-bs-toggle, no custom JS) showing whether the record is
// ai_created/ai_modified and whether it still needs review.
final class AiMetadataBadgeFactory
{
    public function __construct(private readonly IconFactory $iconFactory)
    {
        // ComponentFactory is fetched lazily in createButton() instead of being
        // constructor-injected: this class is instantiated by both the v14 and
        // v13 event listeners (Services.yaml autowires the whole Classes/*
        // directory), and ComponentFactory doesn't exist on v13 - a constructor
        // type-hint for it would fail container compilation there. Once v13
        // support is dropped, this can go back to normal constructor injection.
    }

    public function createButton(AiMetadata $metadata, string $href): DropDownButton
    {
        $componentFactory = GeneralUtility::makeInstance(ComponentFactory::class);
        $languageService = $GLOBALS['LANG'];

        $flagParts = [];
        if ($metadata->isAiCreated()) {
            $flagParts[] = $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_created');
        }
        if ($metadata->isAiModified()) {
            $flagParts[] = $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_modified');
        }
        $flagLabel = implode(', ', $flagParts);

        $dropDown = $componentFactory->createDropDownButton()
            ->setLabel($flagLabel)
            ->setTitle($flagLabel)
            ->setIcon($this->iconFactory->getIcon('ai-label-sparkle', IconSize::SMALL));

        $dropDown->addItem($componentFactory->createDropDownHeader()->setLabel($flagLabel));

        if ($metadata->isReviewed()) {
            $dropDown->addItem(
                $componentFactory->createDropDownItem()
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
                $componentFactory->createDropDownItem()
                    ->setTag('a')
                    ->setHref($href)
                    ->setIcon($this->iconFactory->getIcon('actions-info', IconSize::SMALL))
                    ->setLabel($languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:notice.reviewRequired'))
            );
        }

        return $dropDown;
    }
}
