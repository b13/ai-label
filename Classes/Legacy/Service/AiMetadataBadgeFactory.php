<?php

declare(strict_types=1);

namespace B13\AiLabel\Legacy\Service;

use B13\AiLabel\Domain\Model\AiMetadata;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;

// TYPO3 v13 only - see Classes/Service/AiMetadataBadgeFactory.php for the v14+
// equivalent (ComponentFactory/DropDownButton don't exist on v13). Once v13
// support is dropped, this whole Classes/Legacy/ directory can just be deleted.
//
// Builds the same marker as the v14 factory, but as a plain HTML string: v13's
// record list/file list events want setAction() to receive raw HTML, not a
// component object. Markup matches what TYPO3's own DropDownButton renders, so
// it looks identical - a native Bootstrap dropdown (data-bs-toggle, no custom JS).
final class AiMetadataBadgeFactory
{
    public function __construct(private readonly IconFactory $iconFactory)
    {
    }

    public function createButtonHtml(AiMetadata $metadata, string $href): string
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
}
