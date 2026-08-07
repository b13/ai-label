# EXT:ai_label

Flags backend records as AI-created / AI-modified, with an editorial review
workflow, backend markers (Web>List, File>Filelist, Page/Layout module), and an
overview module listing every flagged record.

- Extension key: `ai_label`
- Composer package: `b13/ai-label`
- PHP namespace: `B13\AiLabel`
- Compatible with TYPO3 v13.4 and v14.3+

## What it does

- Adds a `tx_ailabel_origin` select (`No AI involvement` / `AI created` / `AI
  modified`, exclusive - a record is never both) plus a `tx_ailabel_reviewed`
  checkbox to every applicable table (`tt_content`, `pages`, `sys_file_metadata`
  by default - extensible via `ApplicableTablesEvent`). Field names are prefixed
  to avoid colliding with a same-named field from another extension.
- State is stored in a single `tx_ailabel_metadata` JSON column added directly
  to each applicable table's schema - no separate table, no TCA-visible real
  columns for the select/checkbox themselves. Internally the JSON keys stay
  short (`origin`, `reviewed_by`, `reviewed_timestamp`) since that column is
  private to this extension.
- As long as a record is flagged, changing its content resets the review
  state, so an editor has to review it again - unless the same save also
  (re-)ticks "reviewed".
- Shows an "AI" marker with the review status in the Web>List module, the
  File>Filelist module, and the Page/Layout module (both the page itself and
  its content elements).
- Backend module "AI Label" (Web menu) lists every currently flagged record,
  workspace-aware.
- Changing a flagged file's actual content (replacing it, or overwriting its
  contents directly) shows a flash message in the backend reminding the editor
  to double-check whether the stored AI origin is still correct - the origin
  itself isn't touched automatically. If the file had already been reviewed,
  that review is reset (back to "review required"), the same way changing a
  flagged content element's/page's content does.

## Public API for other extensions

Other extensions that generate or edit content with AI (e.g. an AI text/image
generator) can flag a record directly, without going through the backend form,
via `B13\AiLabel\Service\AiLabelApi` (constructor-inject it like any other
service):

```php
final class AiLabelApi
{
    public function aiCreated(string $table, int $uid, ?BackendUserAuthentication $user = null): void;
    public function aiModified(string $table, int $uid, ?BackendUserAuthentication $user = null): void;
    public function aiRemoved(string $table, int $uid, ?BackendUserAuthentication $user = null): void;
    public function aiMetadataUpdate(string $table, int $uid, ?AiMetadata $aiMetadata, ?BackendUserAuthentication $user = null): void;
}
```

```php
$this->aiLabelApi->aiCreated('tt_content', 123);
$this->aiLabelApi->aiModified('tt_content', 123, $someSpecificBackendUser);
$this->aiLabelApi->aiRemoved('tt_content', 123);
```

What this does for you:

- **Writes through a real `DataHandler` run**, not a raw query - the same
  permission checks apply as for any other backend edit (page/table access for
  the given backend user), and the change shows up in that record's history
  (`sys_history`) like a normal edit would.
- **`$user` defaults to `$GLOBALS['BE_USER']`** if not given; if neither is
  available, it throws `\RuntimeException`.
- **`aiCreated()`/`aiModified()` always reset the review state** (`reviewed_by`/
  `reviewedTimestamp` back to "needs review") - every call means the content was
  just (re-)touched by AI, regardless of whether it was reviewed before.
- **The AI origin is exclusive** - a record is either untouched by AI,
  AI-created, or AI-modified, never more than one at once. Calling
  `aiModified()` on a record that was `aiCreated()` replaces the origin, it
  doesn't add to it.
- **`aiRemoved()` clears the flag entirely** - origin back to "no AI
  involvement", not just a boolean toggled off.
- **Throws `\InvalidArgumentException`** if `$table` isn't one of the applicable
  tables (see below) - this API never silently writes `tx_ailabel_metadata` onto
  a table that was never set up to carry it.

`aiMetadataUpdate()` is the lower-level method the three convenience methods
above are built on; use it directly if you've already computed the full
`AiMetadata` state yourself. `$aiMetadata = null` clears the column, same as
`aiRemoved()`.

### Registering your own tables

By default, `tt_content`, `pages` and `sys_file_metadata` are applicable (get
the `tx_ailabel_origin`/`tx_ailabel_reviewed` fields, the `tx_ailabel_metadata`
column, and can be used with `AiLabelApi`). To add your own table, listen to
`B13\AiLabel\Event\ApplicableTablesEvent`:

```php
#[AsEventListener]
final class RegisterMyTableForAiLabel
{
    public function __invoke(ApplicableTablesEvent $event): void
    {
        $event->addApplicableTable('tx_myextension_domain_model_something');
    }
}
```

(`removeApplicableTable()` is available too, if you need to opt a default
table back out.)

## Frontend integration

This extension renders a small AI-origin marker on every content element
that is flagged (`tx_ailabel_origin` = "AI created" or "AI modified"), once
its TypoScript is included in your project:

**Site-Set-based projects** (TYPO3 v13.4+): add `b13/ai-label` to your own
Site Set's `dependencies` in `config.yaml` (for discoverability/settings
merging), **and** explicitly `@import` its TypoScript in your own Set's
`setup.typoscript` - listing a dependency alone does **not** pull in its
TypoScript:

```yaml
# config.yaml
dependencies:
  - b13/ai-label
```

```typoscript
# setup.typoscript
@import 'EXT:ai_label/Configuration/TypoScript/setup.typoscript'
```

**Classic TypoScript template projects**: select "Include static: AI Label"
in the TypoScript template module (Web > Template), or `@import` the same
file directly.

Once included, it hooks into `EXT:fluid_styled_content`'s `lib.contentElement`
via a higher-priority `partialRootPaths` entry (`Configuration/Sets/AiLabel/setup.typoscript`)
that overrides `Partials/DropIn/After/All.html` - an extension point
`EXT:fluid_styled_content` ships intentionally empty for exactly this
purpose, already rendered after every content element's main output by its
`Layouts/Default.html`:

```html
<f:render section="After" optional="true">
    <f:render partial="DropIn/After/All" arguments="{_all}" />
</f:render>
```

This extension's `DropIn/After/All.html` (`Resources/Private/Partials/DropIn/After/All.html`)
just adds one line to that otherwise-empty placeholder:

```html
<f:render partial="AiLabel" arguments="{record: data}" />
```

The `AiLabel` partial (`Resources/Private/Partials/AiLabel.html`):

- Resolves the current record's `AiMetadata` via `<ailabel:recordMetadata>`
  (or, if a `file` argument is passed, e.g. from your own template,
  `<ailabel:fileMetadata>`).
- Renders nothing unless `aiMetadata.flagged` is true.
- Outputs one of eight bundled SVG icons
  (`Resources/Public/Icons/ai_generated_*.svg` / `ai_modified_*.svg` -
  `black`/`white` x plain/`_transparent`, selected via the optional `variant`
  argument, default `black`) via `f:image` - no image-processing/asset-pipeline
  dependency beyond core Fluid.
- Ships its own `<f:asset.css>` for the `.b_ai-label`/`.b_ai-label__icon`
  classes, positioned entirely through CSS custom properties
  (`--ai-label-position`, `--ai-label-inset`, `--ai-label-margin`,
  `--ai-label-gridcolumn`, `--ai-label-alignitems`, `--ai-label-zindex`,
  `--ai-label-icon-width`, `--ai-label-icon-height`) - set these on a
  surrounding container in your own CSS to position the marker for a given
  content element/component; the defaults just anchor it bottom-right.

### Overriding the default markup/icons

Projects that want their own icon set, markup, or positioning can override the
partial with a higher-priority `partialRootPaths` entry pointing to their own
`AiLabel.html` (same filename, same argument contract - optional `file`,
`record`, `variant`):

```typoscript
lib.contentElement {
    partialRootPaths {
        2000 = EXT:my_sitepackage/Resources/Private/Partials/
    }
}
```

### Upgrading an existing project with manual `AiLabel` calls

If your project already renders an `AiLabel` partial manually per template
(e.g. `<f:render partial="AiLabel" arguments="{record: record}" />` inside
individual content element templates), pulling in this version of the
extension renders it a **second time**, through the new automatic `DropIn/After/All`
hook. To opt out and keep your existing manual calls as the only source,
register your own `partialRootPaths` entry at a higher priority than the
extension's (`1550`, see `Configuration/Sets/AiLabel/setup.typoscript`) with a
copy of `DropIn/After/All.html` that's empty again (i.e. the original
`EXT:fluid_styled_content/Resources/Private/Partials/DropIn/After/All.html`
placeholder, without the added `<f:render partial="AiLabel" .../>` line):

```typoscript
lib.contentElement {
    partialRootPaths {
        2000 = EXT:my_sitepackage/Resources/Private/Partials/
    }
}
```

That's the only change needed - your existing per-template calls keep working
unmodified.

### Lower-level building blocks

The automatic rendering above is built entirely on the same public building
blocks documented below - `AiMetadata`, the DataProcessor and the two
ViewHelpers. Use them directly if you need the flag somewhere the automatic
Footer hook doesn't reach (e.g. a custom Layout that doesn't use
`fluid_styled_content`'s `Default` layout, or a per-image marker inside a
gallery).

`AiMetadata` (`B13\AiLabel\Domain\Model\AiMetadata`) exposes:

```php
$aiMetadata->getOrigin(): AiOrigin   // B13\AiLabel\Domain\Enum\AiOrigin: Human|Generated|Manipulated
$aiMetadata->isAiCreated(): bool     // getOrigin() === AiOrigin::Generated
$aiMetadata->isAiModified(): bool    // getOrigin() === AiOrigin::Manipulated
$aiMetadata->isFlagged(): bool       // getOrigin() !== AiOrigin::Human
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

`<ailabel:fileMetadata>` does the same for a FAL file reference (`tx_ailabel_metadata`
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

## Credits

This extension was created by Achim Fritz in 2026 for [b13 GmbH, Stuttgart](https://b13.com).

[Find more TYPO3 extensions we have developed](https://b13.com/useful-typo3-extensions-from-b13-to-you) that help us deliver value in client projects. As part of our work, we focus on testing and best practices to ensure long-term performance, reliability, and results in all our code.
