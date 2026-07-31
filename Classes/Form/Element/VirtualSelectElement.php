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

use TYPO3\CMS\Backend\Form\Element\SelectSingleElement;

// TCA type=user delegating to core's selectSingle rendering - same trick as
// VirtualCheckboxElement: DefaultTcaSchema only auto-creates a database column for
// TCA type=select (StaticSelectFieldType), never for type=user, so this renders
// exactly like a normal single select without ever gaining a real column.
// AiMetaDataHandlerHook folds the submitted value into ai_metadata instead.
//
// Unlike type=select, TcaSelectItems (the core provider that normally resolves
// 'items' into the shape SelectSingleElement expects) only runs for
// config.type === 'select' - it never touches this field. AddAiMetaFieldsToTca
// therefore provides 'items' already in the plain ['label' => ..., 'value' => ...]
// shape SelectSingleElement::render() reads directly (plain array access, not
// dependent on TcaSelectItems' SelectItem objects).
final class VirtualSelectElement extends SelectSingleElement
{
}
