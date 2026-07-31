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
// column. AiMetaDataHandlerHook folds the submitted value into ai_metadata instead.
// $this->nodeFactory is inherited from AbstractFormElement (injectNodeFactory()).
final class VirtualCheckboxElement extends CheckboxToggleElement
{
    public function __construct(private readonly AiMetadataBadgeFactory $badgeFactory)
    {
    }

    protected function wrapWithFieldsetAndLegend(string $innerHTML): string
    {
        if ($this->data['fieldName'] === 'reviewed') {
            $aiMetadata = AiMetadata::fromArray($this->data['databaseRow']['ai_metadata'] ?? null);
            if ($aiMetadata->isFlagged() === false) {
                return parent::wrapWithFieldsetAndLegend($innerHTML);
            }
            $legend = htmlspecialchars($this->data['parameterArray']['fieldConf']['label'] ?? '');
            if ($this->getBackendUser()->shallDisplayDebugInformation()) {
                $fieldName = $this->data['flexFormContainerFieldName'] ?? $this->data['flexFormFieldName'] ?? $this->data['fieldName'];
                $legend .= ' <code>[' . htmlspecialchars($fieldName) . ']</code>';
            }
            $html = [];
            $html[] = '<fieldset>';
            $html[] =     '<legend class="form-label t3js-formengine-label">' . $legend . '</legend>';
            $html[] =     $this->badgeFactory->getBadge($aiMetadata);
            $html[] =     $innerHTML;
            $html[] = '</fieldset>';
            return implode(chr(10), $html);
        }
        return parent::wrapWithFieldsetAndLegend($innerHTML);
    }
}
