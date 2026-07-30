<?php

declare(strict_types=1);

namespace B13\AiLabel\Form\Element;

use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;

// TCA type=user delegating to core's checkboxToggle rendering. DefaultTcaSchema only
// auto-creates a database column for TCA type=check fields, never for type=user -
// so this renders exactly like a normal checkbox toggle without ever gaining a real
// column. AiMetaDataHandlerHook folds the submitted value into ai_metadata instead.
// $this->nodeFactory is inherited from AbstractFormElement (injectNodeFactory()).
final class VirtualCheckboxElement extends AbstractFormElement
{
    public function render(): array
    {
        $data = $this->data;
        $data['renderType'] = 'checkboxToggle';

        return $this->nodeFactory->create($data)->render();
    }
}
