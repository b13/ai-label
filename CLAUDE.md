# EXT:ai_label

Flags backend records as AI-created/AI-modified, with an editorial review workflow,
backend markers (Web>List, File>Filelist, Page/Layout module), an overview module,
and frontend passthrough of the flag data. See `README.md` for the user-facing
(integrator) documentation, especially the Frontend Integration section.

- Extension key `ai_label`, composer package `b13/ai-label`, PHP namespace `B13\AiLabel`
- Must work on **both TYPO3 v13.4 and v14.3+** - this drives most of the architecture below
- `require`: `typo3/cms-backend`, `typo3/cms-frontend` (hard dependency - see "Optional
  dependencies" below for why). `typo3/cms-filelist` and `typo3/cms-workspaces` are
  `require-dev` only.

## Data model

- No separate table. A JSON column `tx_ailabel_metadata` is added directly to each
  applicable table's own schema (`tt_content`, `pages`, `sys_file_metadata` by default,
  extensible via `ApplicableTablesEvent`/`ApplicableTablesProvider`), with
  `'nullable' => true, 'default' => null` in its TCA config (see "Gotchas" below for
  why that's required).
- All TCA field/column names are prefixed `tx_ailabel_...` to avoid colliding with a
  same-named field another extension might add to the same table. The JSON keys
  *inside* the metadata column stay short/unprefixed (`origin`, `reviewed_by`,
  `reviewed_timestamp`) - that column is entirely private to this extension, so there's
  no cross-extension collision risk to guard against there. Don't conflate the two: a
  TCA field name and the JSON key it ends up folded into under the same logical concept
  are deliberately different strings now (e.g. field `tx_ailabel_origin` <-> JSON key
  `origin`).
- The AI origin is exclusive - a record is either untouched, AI-created, or AI-modified,
  never more than one at once. This is modeled as `B13\AiLabel\Domain\Enum\AiOrigin: int`
  (`Human = 0`, `Generated = 1`, `Manipulated = 2` - taken verbatim from the user's
  wiki.txt spec, the one piece of that spec that was actually adopted, see "Frontend
  integration" below for what wasn't). `tx_ailabel_reviewed` stays a separate
  boolean-shaped field, untouched by this.
- `tx_ailabel_origin`/`tx_ailabel_reviewed` are TCA `type=user` fields (never
  `type=select`/`type=check`, which would make `DefaultTcaSchema` auto-create a real DB
  column) - `VirtualSelectElement`/`VirtualCheckboxElement` render them exactly like
  `selectSingle`/`checkboxToggle` by extending `SelectSingleElement`/`CheckboxToggleElement`
  directly. `TcaSelectItems` (the core provider that normally resolves a select's `items`
  into the shape `SelectSingleElement::render()` needs) only runs for
  `config.type === 'select'`, so it never touches `tx_ailabel_origin` -
  `AddAiMetaFieldsToTca` therefore provides `items` already in the plain
  `['label' => ..., 'value' => ...]` shape `render()` reads directly.
- Everything is read/written through `B13\AiLabel\Domain\Model\AiMetadata` - an immutable
  value object wrapping the JSON (`getOrigin()`, `isAiCreated()`, `isAiModified()`,
  `isFlagged()`, `isReviewed()`, `getReviewedBy()`, `getReviewedTimestamp()`, `withOrigin()`/
  `withReviewedBy()`/`withReviewedTimestamp()` cloners, `toArray()`). `isAiCreated()`/
  `isAiModified()` are read-only derived accessors over `getOrigin()` - kept for a stable
  public API (README, Fluid templates) even though the mutator side collapsed to a single
  `withOrigin(AiOrigin)`. Always go through this object - don't hand-roll JSON decode/encode
  elsewhere.
- Unflagged records store `tx_ailabel_metadata = NULL` (not all-zero JSON), so
  `AiMetadataRecordFinder`'s `WHERE tx_ailabel_metadata IS NOT NULL` stays a cheap,
  accurate filter. Exception: writes through `AiLabelApi`/`DataHandler` always end up as
  `{"origin": 0, ...}` instead of `NULL` - see "Gotchas" below.

## The review workflow (Classes/Hooks/AiMetaDataHandlerHook.php)

- "reviewed" is persisted as `reviewed_by` (a be_users uid, 0 = "review required") plus
  `reviewed_timestamp`, not a plain boolean.
- Two-stage hook, deliberately split:
  - `processDatamap_preProcessFieldArray` runs *before* DataHandler's own
    `compareFieldArrayWithCurrentAndUnset()` - strips the two virtual fields
    (`tx_ailabel_origin`, `tx_ailabel_reviewed`) from `$incomingFieldArray` before that
    method reads `$currentRecord[$col]` for each of them (undefined array key otherwise,
    which TYPO3's error handler escalates to a fatal exception, since these fields have
    no real column).
  - `processDatamap_postProcessFieldArray` does the actual decision-making and writes
    `$fieldArray['tx_ailabel_metadata']` as a **plain PHP array** (DataHandler/Doctrine
    JSON-encode it themselves for json-typed columns - encoding it yourself
    double-encodes it).
- Business rule: as long as a record is flagged, a save that changes real content resets
  `reviewed_by` to 0 - *unless* that same save also actively ticks "reviewed" from
  unreviewed to reviewed ("reviewed wins"). Reviewed merely *staying* ticked (checkbox
  untouched) does NOT count as "reviewed wins" - content changing after a record was
  already reviewed must still reset it. See `AiMetaDataHandlerHookTest` for the exact
  scenarios this covers.
- `hasRelevantContentChange()` filters out `ctrl.tstamp`, the language-diff-source field,
  and any field with `config.MM` before deciding whether anything actually changed -
  via `TcaSchemaFactory`/`TcaSchemaCapability` (identical API on v13.4 and v14, verified),
  not raw `$GLOBALS['TCA']` array access.
- `reviewed_timestamp` comes from `Context`'s `date` aspect
  (`getPropertyFromAspect('date', 'timestamp')`), not `$GLOBALS['EXEC_TIME']`/`time()`
  directly - it's the same underlying value, just the idiomatic accessor. Tests freeze
  time by setting `$GLOBALS['EXEC_TIME']` in `setUp()` *before* anything touches the
  Context's date aspect (it lazily caches on first access).
- This hook is instantiated by DataHandler's legacy `processDatamapClass` mechanism
  (`GeneralUtility::makeInstance()`, not full DI) - it needs `#[Autoconfigure(public: true)]`
  or autowiring silently fails to inject its dependencies. Same applies to DataProcessors
  and Fluid ViewHelpers (`TYPO3\CMS\Frontend\...\ContentDataProcessor` and Fluid's
  `ViewHelperResolver` both instantiate via `GeneralUtility::makeInstance()` too).

## Gotchas

- **`tx_ailabel_metadata`'s TCA config needs `'nullable' => true, 'default' => null`.**
  Without it, core's `DatabaseRowDefaultValues` FormDataProvider force-casts the field to
  `''` (empty string) before `EnrichAiMetaData` ever sees it - both for an existing record
  whose column is genuinely SQL `NULL` (`isset()` is `false` for a `null` array value, so
  `DatabaseRowDefaultValues` never takes its "keep current value" branch) and for a brand
  new record (no TCA default, same `''` fallback). The nullable/default config makes that
  provider preserve/produce PHP `null` in both cases instead, which `AiMetadata::fromArray()`
  already handles. Confirmed by temporarily reverting the config and watching
  `EnrichAiMetaDataTest` fail with the exact reported production error - see that test.
- **`DataHandler::checkValueForJson()` always coerces an explicitly submitted `null` into
  `[]`, never SQL `NULL`.** This means `AiLabelApi::aiRemoved()`/`aiMetadataUpdate(...,
  null, ...)` writes `{"origin": 0, ...}`, not `NULL` - unlike the old
  `AiMetaDataHandlerHook`-direct-`$fieldArray` write path for brand new records, which can
  still produce a real `NULL` (see `NewRecordWithoutAiFlagsResult.csv`). Accepted tradeoff,
  not worked around (would mean bypassing DataHandler) - only effect is
  `AiMetadataRecordFinder`'s `WHERE tx_ailabel_metadata IS NOT NULL` becomes a slightly
  less tight pre-filter (a few never-actually-flagged rows pass it and get discarded in
  PHP via `isFlagged()` afterwards).
- **TCA field names and the JSON keys inside the metadata column are intentionally
  different strings** (see "Data model") - e.g. the form submits `tx_ailabel_origin`,
  but `AiMetadata::toArray()`/`fromArray()` read/write the JSON key `origin`. Don't
  assume they match when tracing a value through `AiMetaDataHandlerHook`.

## v13/v14 compatibility architecture

Explicit user-dictated pattern, don't deviate without asking: for anything that differs
between v13 and v14, keep the v14 class as-is in `Classes/`, add an **early return if
`Typo3Version::getMajorVersion() < 14`**, then create a mirror class in `Classes/Legacy/`
with the inverse guard (`>= 14`). This means the whole `Classes/Legacy/` directory can
just be deleted once v13 support is dropped. `Classes/Legacy/` is excluded from
phpstan (`Build/phpstan.neon`).

**Only do this when there's an actual API difference.** Several classes that originally
had this split were later merged back into one version-agnostic class once it turned out
nothing version-specific remained (e.g. `MarkFlaggedPageInLayoutModule` - `getBadge()`
doesn't touch `ComponentFactory`, and `ModifyPageLayoutContentEvent` is identical on both
versions). Always check first whether the split is still needed before adding one.

Current split: `AiMetadataBadgeFactory` (v14 `createButton()` uses `ComponentFactory`,
lazily via `GeneralUtility::makeInstance()` since it can't be constructor-injected - this
class is instantiated on both versions; v13 `createButtonHtml()` builds raw HTML),
`MarkFlaggedRecordsInRecordList`, `MarkFlaggedFilesInFileList` (their v13/v14 event
classes have the same name but different constructors/methods).

Known version-safe APIs (confirmed identical on v13.4 and v14, no split needed):
`TcaSchemaFactory`/`TcaSchemaCapability`, `BackendUtility::workspaceOL()`,
`RecordFactory`/`RecordInterface`, `Context`, `ModifyPageLayoutContentEvent`,
`RecordTransformationProcessor` ("record-transformation" DataProcessor).

## Backend UI

- `AiMetadataBadgeFactory::getBadge()` is the single source of truth for label/color
  (`ReviewStatus` value object) - used by the record list/file list dropdowns, the layout
  module badges, the form legend (`VirtualCheckboxElement`), and the overview module.
  Never duplicate the "review required" vs "reviewed by X on Y" logic elsewhere.
- No icons on badges/dropdowns (removed - "sieht scheisse aus"), no dropdown-item colors
  either (also removed) - keep it plain text badges.
- Content-element badges in the Page/Layout module: there is no PSR-14 event to render
  into a content element's own `t3-page-ce-header-right` button group (confirmed by
  reading `EXT:backend`'s `RecordDefault/Header.fluid.html` partial - it's fully
  hardcoded, no slot). `MarkFlaggedPageInLayoutModule` embeds flagged content elements'
  badge HTML as JSON (`<script type="application/json" id="ai-label-content-badges">`)
  in the page header via `ModifyPageLayoutContentEvent`, and `Resources/Public/JavaScript/main.js`
  (loaded via `Configuration/JavaScriptModules.php`, `@b13/ai-label/main.js`) injects each
  badge into the matching `.t3-page-ce[data-uid=X] .t3-page-ce-header-right .btn-group`
  client-side. No AJAX call - the data is already server-rendered, JS only relocates it.
  This also means it works on v13 for free (`ModifyPageLayoutContentEvent` is identical
  there), unlike the old `AfterPageContentPreviewRenderedEvent`-based approach it replaced.
- `AiMetadataRecordFinder` is workspace-aware: live records only in the base query
  (`t3ver_wsid = 0`), overlaid via `BackendUtility::workspaceOL()` for the current
  workspace, plus a separate query for brand-new-in-workspace records
  (`t3ver_state = NEW_PLACEHOLDER`), and excludes delete-placeholders. Used by both the
  overview module (all applicable tables) and `MarkFlaggedPageInLayoutModule`
  (`findFlaggedContentElementsOnPage()`, tt_content scoped to one page).
- Badge links (record list/file list dropdown items, layout module badges, overview
  module) all point to `record_edit` with `returnUrl` back to where you were. The form
  legend badge (`VirtualCheckboxElement`) and `AiMetadataRecordFinder`'s own `reviewBadge`
  field are unlinked (`getBadge($metadata)` without `$href`) - callers that need a link
  rebuild the badge themselves with `getBadge($metadata, $href)`.

## Frontend integration

Deliberately minimal: `AiLabelProcessor` (DataProcessor) and `RecordMetadataViewHelper`/
`FileMetadataViewHelper` just hand the `AiMetadata` object through to Fluid - **no**
decision DTO, no rendered HTML, no opinionated label text. An earlier attempt built a
whole `AiLabelDecision`/`AiOrigin`/`AiLabelService` layer modeled on a wiki.txt spec the
user never asked for - that was explicitly rejected and removed. `AiOrigin` later came
back (see "Data model") because the user explicitly asked for that one specific piece
("den enum kannste so von der wiki.txt übernehmen") to replace the old two-boolean
aiCreated/aiModified shape - but `AiLabelDecision`/`AiLabelService`/rendered-HTML/
aria-label/site-settings config from that same spec were NOT re-requested and still
don't exist. If asked to extend the frontend integration further, keep following the
"just pass the object through" principle and don't reach back into wiki.txt for more
unless explicitly told to.

Both ViewHelpers return the `AiMetadata` object directly when used inline
(`{ailabel:recordMetadata(record: data)}`), but return `''` when the optional `as`
argument is used to assign a variable directly (same convention as `f:variable` - a
standalone tag's return value gets string-cast into the output stream, and `AiMetadata`
has no `__toString()`, so it must not "leak" that way).

## Optional dependencies

`typo3/cms-filelist` is `require-dev` only - `MarkFlaggedFilesInFileList` only
references `ProcessFileListActionsEvent` as a method-parameter type hint, which PHP
resolves lazily (only when actually invoked). If filelist isn't installed, the class
still loads fine and the listener is simply dormant (event never fires).

`typo3/cms-frontend` must stay a hard `require`: `AiLabelProcessor implements
DataProcessorInterface` is a class-declaration-level dependency (`implements`, not a
type hint) - PHP resolves that eagerly when the file is loaded, and `Services.yaml`'s
`resource: '../Classes/*'` autowiring scan loads every class regardless of whether it's
ever used. Missing `cms-frontend` would hard-crash the *entire* container compilation,
not just the DataProcessor. (`typo3/cms-workspaces` would have the same problem if
`AiMetadataRecordFinder` ever started implementing a workspaces-provided interface -
currently it only calls static `BackendUtility` methods, which stays lazy/safe.)

Before adding a new hard dependency, check whether the usage is a type hint (safe,
can be require-dev) or an `implements`/`extends`/eagerly-instantiated dependency
(unsafe, must be a real `require`).

## Testing

- Functional tests only (`typo3/testing-framework`), no unit tests. Run:
  ```
  php -d memory_limit=2G .Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml Tests/Functional
  php -d memory_limit=2G .Build/bin/phpstan analyse -c Build/phpstan.neon
  ```
- **CSV fixture format is easy to get wrong**: the table name must be on its own line
  (first column only), *then* a separate line with a leading empty column + field names,
  *then* data rows (leading empty column). Table name + field names on the same line
  (e.g. `tt_content,uid,pid,header`) parses "successfully" but silently produces zero
  imported rows and a barely-related PHP warning deep in `DataSet.php` - this bit us once,
  see `git log` around the CSV-format fix. Reference format:
  ```
  tt_content
  ,uid,pid,header,tx_ailabel_metadata
  ,1,1,Some content,"{""origin"":1,""reviewed_by"":0,""reviewed_timestamp"":0}"
  ```
  `\NULL` (backslash prefix) is the special literal for SQL NULL - bare `NULL` is a
  4-character string literal.
- Comparing a JSON column's value via `assertCSVDataSet()` compares the *raw DB string*,
  not a decoded structure - MySQL's `JSON` column type normalizes stored text with a
  space after `:` and `,` when read back (`{"a": 1, "b": 2}`, not `{"a":1,"b":2}`). Write
  fixtures accordingly or the assertion fails on whitespace alone.
- Any table with `ctrl.versioningWS` gets TCA-schema-based workspace handling for free
  in `AiMetadataRecordFinder` - if you add workspace-related tests, remember
  `coreExtensionsToLoad` needs `'workspaces'` (`BackendUtility::workspaceOL()` no-ops
  silently without it, so a missing entry fails quietly, not loudly) and
  `typo3/cms-workspaces` needs to be present in `require-dev`.
- `coreExtensionsToLoad` also needs `'filelist'` for any test that boots the full
  extension (composer-required at dev-time even though not at runtime - see above).
- Testing DataProcessors: just instantiate directly and call `->process($cObj, ...)` -
  no Fluid needed, see `AiLabelProcessorTest`.
- Testing ViewHelpers: needs an actual Fluid render pass to be meaningful (namespace
  resolution, argument binding, `as`-assignment side effects are all part of what you're
  testing). Use `TYPO3\CMS\Core\View\ViewFactoryInterface` (the v13/v14 successor to the
  removed `StandaloneView`) with a real fixture template file under
  `Tests/Functional/ViewHelpers/Fixtures/Templates/` - there's no "render from string"
  API, `ViewFactoryData` only takes `templateRootPaths`/`templatePathAndFilename`.
- Testing FAL (`FileMetadataViewHelperTest`): constructing `new FileReference(['uid_local' => N])`
  needs a real `sys_file_storage` (Local driver, flex `configuration` field - see
  `Tests/Functional/ViewHelpers/Fixtures/FlaggedFile.csv` for the exact FlexForm XML
  shape) + `sys_file` + `sys_file_metadata` row. No physical file on disk is needed for
  pure property reads (`getProperty()` never touches the filesystem) - only the storage's
  `basePath` needs to exist, and every generated test instance already has a `fileadmin/`
  folder.
- PHP booleans interpolated into Fluid text nodes cast via plain PHP rules: `true` -> `'1'`,
  `false` -> `''` (empty string, *not* `'0'`) - easy to get wrong when writing expected
  test output strings.
- Testing a custom FormEngine element directly (`VirtualSelectElementTest`): get it from
  the container (`$this->get(VirtualSelectElement::class)`, not `new` - it needs
  `injectNodeFactory()` DI, which the container wires but a bare constructor call won't),
  call `->setData([...])` with a minimal hand-built result array, then `->render()`. Needs
  `$GLOBALS['BE_USER']`/`$GLOBALS['LANG']` set up (same as `AbstractDatahandler`) or
  `getBackendUser()`/`getLanguageService()` throw `TypeError` on the `null` global. The
  inherited `defaultFieldWizard` (`otherLanguageContent`, `defaultLanguageDifferences`)
  reads `$this->data['processedTca']['columns'][$fieldName]` unconditionally, so that key
  must be present too (real TCA config, e.g.
  `$GLOBALS['TCA']['tt_content']['columns']['tx_ailabel_origin']`) even though the test
  itself never touches TCA directly - a missing `processedTca` key triggers PHP warnings,
  not a hard failure, so it's easy to miss.

## Coding conventions

- License header (after `namespace`, before `use` statements, blank line on both sides):
  ```php
  /*
   * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
   *
   * It is free software; you can redistribute it and/or modify it under
   * the terms of the GNU General Public License, either version 2
   * of the License, or any later version.
   */
  ```
  on every PHP file, including tests.
- English comments only, and only where the *why* isn't obvious from the code.
- No double-quoted string interpolation (`"$table:$id"`) - use concatenation
  (`$table . ':' . $id`).
- Prefer the domain object's own accessors over re-deriving booleans/values inline
  (e.g. `$pendingAiMetadata->isFlagged()` over reconstructing `$origin !== AiOrigin::Human`
  from a separately-destructured local variable).
