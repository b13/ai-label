<?php

declare(strict_types=1);

namespace B13\AiLabel\Service;

use B13\AiLabel\Domain\Model\AiMetadata;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
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
        // v14
        $componentFactory = GeneralUtility::makeInstance(ComponentFactory::class);
        $languageService = $this->getLanguageService();

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

    public function createButtonHtml(AiMetadata $metadata, string $href): string
    {
        // v13
        $languageService = $this->getLanguageService();

        $flagParts = [];
        if ($metadata->isAiCreated()) {
            $flagParts[] = $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_created');
        }
        if ($metadata->isAiModified()) {
            $flagParts[] = $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_modified');
        }
        $flagLabel = implode(', ', $flagParts);

        if ($metadata->isReviewed()) {
            $statusIcon = $this->iconFactory->getIcon('actions-check', IconSize::SMALL)->render();
            $statusLabel = sprintf(
                $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:recordlist.reviewedOn'),
                date('d.m.Y', $metadata->getReviewedDate())
            );
        } else {
            $statusIcon = $this->iconFactory->getIcon('actions-info', IconSize::SMALL)->render();
            $statusLabel = $languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:notice.reviewRequired');
        }

        return '<div class="btn-group">'
            . '<button type="button" class="btn btn-sm btn-default dropdown-toggle" data-bs-toggle="dropdown"'
            . ' aria-expanded="false" aria-label="' . htmlspecialchars($flagLabel) . '" title="' . htmlspecialchars($flagLabel) . '">'
            . $this->iconFactory->getIcon('ai-label-sparkle', IconSize::SMALL)->render()
            . '</button>'
            . '<ul class="dropdown-menu">'
            . '<li><h6 class="dropdown-header">' . htmlspecialchars($flagLabel) . '</h6></li>'
            . '<li><a class="dropdown-item" href="' . htmlspecialchars($href) . '">' . $statusIcon . ' ' . htmlspecialchars($statusLabel) . '</a></li>'
            . '</ul>'
            . '</div>';
    }

    public function getBadge(AiMetadata $aiMetadata): string
    {
        if ($aiMetadata->isReviewed()) {
            $reviewedBy = $aiMetadata->getReviewedBy();
            $beUser = BackendUtility::getRecord('be_users', $reviewedBy);
            $reviewedDate = $aiMetadata->getReviewedDate();
            $html = [];
            $html[] = htmlspecialchars(
                $this->getLanguageService()->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:notice.reviewed.1')
            );
            $userName = '[' . $reviewedBy . ']';
            if ($beUser !== null) {
                $userName = $beUser['username'] . ' ' . $userName;
            }
            $html[] = $userName;
            $html[] = htmlspecialchars(
                $this->getLanguageService()->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:notice.reviewed.2')
            );
            $html[] = $reviewedDate;
            $label = '<span class="badge badge-info">' . implode(' ', $html) . '</span>';
        } else {
            $label = htmlspecialchars(
                $this->getLanguageService()->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:notice.reviewRequired')
            );
            $label = '<span class="badge badge-warning">' . $label . '</span>';
        }
        return $label;
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
