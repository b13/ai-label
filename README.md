# EXT:ai_label

Mark content in TYPO3 as created or modified by AI, keep track of who checked
it, and show a marker to your visitors - so your website meets the disclosure
rules of the EU AI Act.

## What does it do?

Since 2 August 2026, the EU AI Act requires websites to tell their visitors when
certain content was made or altered by AI. This extension gives your editors a
simple way to record that, and takes care of showing the notice on the website.

**For editors**, every page, content element and image gets a new "AI Metadata"
tab with two things to fill in:

- **AI origin** - was this content created by AI from scratch, modified by AI,
  or is no AI involved at all? Everyday helpers such as spell-checking or
  colour correction don't count.
- **Reviewed** - tick this once you have actually read the content and checked
  that it is correct. TYPO3 remembers who reviewed it and when.

Whenever a flagged record is edited again, the review is cleared automatically,
so changed content always gets a fresh pair of eyes before it counts as checked.

**For visitors**, every flagged content element automatically shows a small AI
marker on the published page - no template work needed. The icons, wording and
position can all be replaced with your own. Optionally, flagged **images** can
carry the marker themselves, either as a layer drawn over the image or burned
into its pixels so it survives being downloaded - see *Marking images
themselves* below.

**For everyone working in the backend**, flagged records are easy to spot: an
"AI" marker with the review status appears in the List module, the Filelist and
the Page module (for the page itself and for each content element). A dedicated
"AI Label" backend module lists every flagged record across the whole site, so
you can see at a glance what still needs reviewing.

If a flagged file is later replaced or overwritten, editors get a reminder to
check whether the stored AI origin still fits - the old classification was made
for the old file, after all - and any existing review of that file is reset.

## How this helps with the EU AI Act

The relevant rule is **Article 50 of the EU AI Act** (Regulation (EU) 2024/1689),
which applies since **2 August 2026**. It puts two duties on you as the operator
of a website that publishes AI content:

1. **AI-generated or AI-altered images, audio and video** that could pass as
   real ("deepfakes") must be disclosed to the visitor.
2. **AI-generated or AI-altered text on matters of public interest** - politics,
   health, public safety, the environment, consumer protection and similar
   topics relevant to public debate - must be disclosed as well. This one has an
   exception: if a person genuinely reviewed the text for substance and someone
   holds editorial responsibility for publishing it, no public label is required.

In both cases the disclosure has to be **clear, easy to understand, and visible
by the time the visitor first sees the content**. A technical watermark alone is
not enough.

This is what the extension maps onto:

| What the law asks for | How the extension covers it |
| --- | --- |
| Record whether content was generated or manipulated by AI | The **AI origin** field, using the law's own wording |
| Show visitors a clear notice at first sight | The **AI marker**, rendered automatically on every flagged content element |
| Human review with editorial responsibility (the text exception) | The **Reviewed** checkbox, storing who checked it and when |
| Keep that review meaningful over time | Editing a flagged record **resets** the review automatically |
| Keep an overview of what is published | The **AI Label** backend module, plus the markers in the List, Filelist and Page modules |

Two things worth knowing:

- **The marker is shown for every flagged record**, including reviewed text.
  The law would allow you to leave the label off properly reviewed text, but
  showing it anyway is always permitted and is the safer default. If you want
  different behaviour, override the `AiLabel` partial (see *Frontend
  integration* below).
- **Human review does not remove the duty for images, audio and video.** The
  exception in Article 50(4) covers text only, so flagged media stays labelled
  no matter how carefully it was checked.

This extension provides the tooling; it cannot make you compliant on its own.
Whether a given piece of content falls under Article 50, and whether your review
process meets the standard the law expects, remains your decision. This document
is not legal advice.

## Requirements

- Extension key: `ai_label`
- Composer package: `b13/ai-label`
- PHP namespace: `B13\AiLabel`
- Compatible with TYPO3 v13.4 and v14.3+

---

## How it works internally

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
- The "AI Label" backend module (Web menu) and the record/file list markers are
  workspace-aware, resolving records for the currently selected workspace.
- The two form fields carry TCA `description` texts explaining the Article 50
  duties in plain language, plus a palette description above them
  (`AddAiMetaFieldsToTca`, labels in `locallang_db.xlf`).
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

### Marking images themselves

By default the marker belongs to the *content element*. A flagged image can also
be marked on the image itself, controlled by one extension configuration setting
(Admin Tools > Settings > Extension Configuration > ai_label > `imageMarker`):

| Value | What happens |
| --- | --- |
| `off` (default) | Nothing changes - only the content element marker. |
| `overlay` | The marker is drawn over the image as a positioned HTML layer. |
| `baked` | The marker is composited into the pixels of the processed image. |

**`overlay`** costs nothing at render time, stays crisp at any size and keeps the
marker's `alt` text, but it is only markup - it is gone the moment the image is
downloaded, hotlinked or shared. It works by overriding
`EXT:fluid_styled_content`'s `Media/Rendering/Image` partial to wrap the image in
`<span class="b_ai-label-image">` and render the `AiLabel` partial inside it,
positioned by the same CSS custom properties as everywhere else (override
`--ai-label-image-inset` to move it). Only flagged images get the wrapper.

> **This only reaches images that `fluid_styled_content` itself renders.** A
> sitepackage with its own media templates - which is most themes - never calls
> that partial, so nothing appears. Such projects should render the marker in
> their own image template instead: wrap the `<img>` in
> `<span class="b_ai-label-image">` and add
> `<f:render partial="AiLabel" arguments="{file: file}" />` next to it.

**`baked`** writes the badge into the image, so the disclosure travels with the
file wherever it ends up. It is implemented as a FAL processor
(`B13\AiLabel\Imaging\AiWatermarkProcessor`) registered ahead of core's
`LocalImageProcessor`, so it runs once per processed variant and the result is
cached like any other processed image. Things to know:

- **The original file is never touched** - only the generated variants under
  `_processed_/`.
- **Requires ImageMagick.** GraphicsMagick's `convert` has no `-composite`
  operator, so sites on GraphicsMagick silently keep the content element marker.
- **SVGs are skipped** (they are never rasterised) as are images narrower than
  160px, where the badge would be unreadable anyway.
- The badge sits bottom right at a constant 160px wide and is never enlarged
  beyond that, so it stays a discreet mark rather than growing with the image.
  Only on images too small to carry it does it shrink, to at most a quarter of
  the image width.
- Only *processed* images are marked. If a template links an original file
  directly, without any processing instruction, it is served unmarked.
- Changing a file's AI flag flushes that file's processed variants, so the
  change takes effect on the next render.

> **Clear the processed files once, after switching this on.** FAL caches
> processed images on the original file, the task and its configuration - none of
> which change when you flip this setting. Variants generated before you enabled
> `baked` therefore stay in place, unmarked, and nothing regenerates them. Run
> this once after enabling (or disabling) the mode:
>
> ```
> vendor/bin/typo3 cleanup:localprocessedfiles --all --dry-run   # inspect first
> vendor/bin/typo3 cleanup:localprocessedfiles --all
> ```
>
> `--all` is required: without it the command only clears orphaned records and
> stubs, and leaves exactly the valid, already-rendered variants you need gone.
> The command lives in EXT:lowlevel. This is only needed when the *setting*
> changes - from then on, changing an individual file's AI flag flushes that
> file's variants by itself.

Both modes are additive to, not a replacement for, the content element marker -
that keeps rendering either way, which is also what images falling into any of
the exclusions above fall back to.

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
