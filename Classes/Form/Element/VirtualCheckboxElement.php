<?php

declare(strict_types=1);

namespace B13\AiLabel\Form\Element;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use TYPO3\CMS\Backend\Form\Element\CheckboxToggleElement;

// TCA type=user delegating to core's checkboxToggle rendering. DefaultTcaSchema only
// auto-creates a database column for TCA type=check fields, never for type=user -
// so this renders exactly like a normal checkbox toggle without ever gaining a real
// column. AiMetaDataHandlerHook folds the submitted value into tx_ailabel_metadata
// instead. $this->nodeFactory is inherited from AbstractFormElement (injectNodeFactory()).
final class VirtualCheckboxElement extends CheckboxToggleElement
{
    public function __construct(private readonly AiMetadataBadgeFactory $badgeFactory)
    {
    }

    protected function wrapWithFieldsetAndLegend(string $innerHTML): string
    {
        if ($this->data['fieldName'] === 'tx_ailabel_reviewed') {
            $aiMetadata = AiMetadata::fromArray($this->data['databaseRow']['tx_ailabel_metadata'] ?? null);
            if ($aiMetadata->isFlagged()) {
                // Prepend the review badge to the element's own markup and let core build the
                // fieldset around both, instead of hand-building the fieldset here. Doing the
                // latter meant duplicating core's legend/debug-info handling, and - on v14 -
                // silently dropping the field's TCA "description": AbstractFormElement renders
                // it from inside this very method there, so an override that never calls the
                // parent swallows it. (On v13 the description arrives as part of $innerHTML
                // instead, via CheckboxToggleElement's "tcaDescription" fieldInformation node -
                // hence the badge sits above the description text there and below it on v14.)
                $innerHTML = $this->badgeFactory->getBadge($aiMetadata) . chr(10) . $innerHTML;
            }
        }
        return parent::wrapWithFieldsetAndLegend($innerHTML);
    }
}
