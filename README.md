# EXT:ai_label

Flags backend records as AI-created / AI-modified, with an editorial review
workflow, backend markers (Web>List, File>Filelist, Page/Layout module), and an
overview module listing every flagged record.

- Extension key: `ai_label`
- Composer package: `b13/ai-label`
- PHP namespace: `B13\AiLabel`
- Compatible with TYPO3 v13.4 and v14.3+

## What it does

- Adds `ai_created` / `ai_modified` checkboxes plus a `reviewed` checkbox to
  every applicable table (`tt_content`, `pages`, `sys_file_metadata` by
  default - extensible via `ApplicableTablesEvent`).
- State is stored in a single `ai_metadata` JSON column added directly to each
  applicable table's schema - no separate table, no TCA-visible real columns
  for the checkboxes themselves.
- As long as a record is flagged, changing its content resets the review
  state, so an editor has to review it again - unless the same save also
  (re-)ticks "reviewed".
- Shows an "AI" marker with the review status in the Web>List module, the
  File>Filelist module, and the Page/Layout module (both the page itself and
  its content elements).
- Backend module "AI Label" (Web menu) lists every currently flagged record,
  workspace-aware.

## Frontend integration

This extension does **not** render anything in the frontend by itself - no
markup, no CSS, no opinionated label text. It only hands the `AiMetadata`
domain object through to your Fluid templates; how (or whether) you display it
is entirely up to you.

`AiMetadata` (`B13\AiLabel\Domain\Model\AiMetadata`) exposes:

```php
$aiMetadata->isAiCreated(): bool
$aiMetadata->isAiModified(): bool
$aiMetadata->isFlagged(): bool       // isAiCreated() || isAiModified()
$aiMetadata->isReviewed(): bool
$aiMetadata->getReviewedBy(): int    // be_users uid, 0 if not reviewed
$aiMetadata->getReviewedTimestamp(): int
```

### Content elements: DataProcessor

Register `AiLabelProcessor` via TypoScript to make the current content
element's `AiMetadata` available as a Fluid variable (default name
`aiMetadata`, configurable via `as`):

```typoscript
tt_content {
    dataProcessing {
        10 = B13\AiLabel\DataProcessing\AiLabelProcessor
        # or, using the short alias registered in Services.yaml:
        10 = ai-label
        10 {
            as = aiMetadata
        }
    }
}
```

In the template:

```html
<f:if condition="{aiMetadata.flagged}">
    <!-- your own markup, e.g. -->
    <span>AI-assisted content</span>
</f:if>
```

### Content elements or any other record: ViewHelper

`<ailabel:recordMetadata>` resolves the `AiMetadata` object for any record row
you already have at hand (useful outside of `tt_content`, or wherever wiring
up a DataProcessor isn't practical):

```html
<html xmlns:ailabel="http://typo3.org/ns/B13/AiLabel/ViewHelpers" data-namespace-typo3-fluid="true">

<!-- assign it directly, no f:variable needed -->
<ailabel:recordMetadata record="{data}" as="aiMetadata" />
<f:if condition="{aiMetadata.flagged}">...</f:if>

<!-- or use it inline, e.g. together with f:variable yourself -->
<f:variable name="aiMetadata" value="{ailabel:recordMetadata(record: data)}" />

</html>
```

### Files / images: ViewHelper

`<ailabel:fileMetadata>` does the same for a FAL file reference (`ai_metadata`
lives on `sys_file_metadata`, not on the file reference itself):

```html
<html xmlns:ailabel="http://typo3.org/ns/B13/AiLabel/ViewHelpers" data-namespace-typo3-fluid="true">

<f:for each="{data.image}" as="image">
    <ailabel:fileMetadata fileReference="{image}" as="aiMetadata" />
    <f:if condition="{aiMetadata.flagged}">
        <!-- your own markup -->
    </f:if>
    <f:image image="{image}" />
</f:for>

</html>
```

Both ViewHelpers render nothing themselves when used with `as` - they only
assign the variable. Without `as`, they return the `AiMetadata` object
directly, so they can be used inline as shown above.
