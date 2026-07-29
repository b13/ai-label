<?php

declare(strict_types=1);

namespace B13\AiLabel\Form\Element;

use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;

// Pure display element (TCA type=user, no input, nothing submitted) shown
// via displayCond next to the "reviewed" checkbox while reviewed is not set.
final class ReviewRequiredNotice extends AbstractFormElement
{
    public function render(): array
    {
        $result = $this->initializeResultArray();
        $label = htmlspecialchars(
            $GLOBALS['LANG']->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:notice.reviewRequired')
        );
        $result['html'] = '<span class="badge badge-warning">' . $label . '</span>';

        return $result;
    }
}
