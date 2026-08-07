# EXT:ai_label

Flags backend records as AI-created/AI-modified, with an editorial review workflow,
backend markers (Web>List, File>Filelist, Page/Layout module), an overview module,
and frontend passthrough of the flag data. See `README.md` for the user-facing
(integrator) documentation, especially the Frontend Integration section.

- Extension key `ai_label`, composer package `b13/ai-label`, PHP namespace `B13\AiLabel`
- Must work on **both TYPO3 v13.4 and v14.3+** - this drives most of the architecture below
- `require`: `typo3/cms-backend`, `typo3/cms-frontend` (hard dependency - see "Optional
  dependencies" below for why). `typo3/cms-filelist`, `typo3/cms-workspaces`, and
  `typo3/cms-fluid-styled-content` are `require-dev` only.

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
  elsewhere. `Services.yaml` explicitly excludes `Classes/Domain/Model/*` and
  `Classes/Domain/Enum/*` from the `resource: '../Classes/*'` autowiring scan - `AiMetadata`/
  `AiOrigin` are plain value objects with no business belonging in the DI container, never
  constructor-injected or `makeInstance()`'d as a service anywhere.
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
  or autowiring silently fails to inject its dependencies (`Context`, `TcaSchemaFactory`,
  `AiLabelApi`). **The rule only bites when there's actually a constructor to autowire**:
  `AiLabelProcessor`/`RecordMetadataViewHelper`/`FileMetadataViewHelper` are also
  instantiated the same way (Fluid's `ViewHelperResolver`/TYPO3 Frontend's
  `ContentDataProcessor`, both via plain `GeneralUtility::makeInstance()`), but none of
  the three has a constructor at all - nothing to inject, so the attribute was
  never needed there and was removed. Don't add it defensively by analogy; check first
  whether the class being `makeInstance()`'d from outside the object graph actually has
  constructor dependencies.

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

- The overview module (`Configuration/Backend/Modules.php`) sets
  `'inheritNavigationComponentFromMainModule' => false` - it's not page-tree-scoped
  (lists flagged records across the whole site), so it shouldn't show the Web module's
  page tree in the navigation component.
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
- `AfterFileContentChangedListener` (from wiki.txt, explicitly requested - same
  one-piece-at-a-time adoption as `AiOrigin`, see "Frontend integration") listens to
  both `AfterFileReplacedEvent` and `AfterFileContentsSetEvent` (same shared handler
  for both, matching core's own `FlushCacheTagForFile` precedent for this exact event
  pair) - a flagged file's origin was set for its *previous* content, so this: (1) adds
  a flash message nudging the editor to re-check the origin classification (it never
  touches the origin itself), and (2) resets an already-reviewed file back to "review
  required", the same rule `AiMetaDataHandlerHook` applies to tt_content/pages content
  changes - except there's no concurrent form submission here, so no "reviewed wins"
  case to reconcile. The reset goes through `AiLabelApi::aiMetadataUpdate()`, same as
  everywhere else (permissions, sys_history). Other FAL events were deliberately not
  hooked into: `AfterFileAddedEvent` is a brand new upload (nothing stored yet to be
  stale), `AfterFileCopiedEvent`/`AfterFileMovedEvent`/`AfterFileRenamedEvent` don't
  change the file's actual content, and `AfterFileMetaData*Event` fire for
  tx_ailabel_metadata's own CRUD, not the file's content changing.
  The review reset itself always happens regardless of context - only the flash
  message is backend-only, guarded with
  `ApplicationType::fromRequest($GLOBALS['TYPO3_REQUEST'])->isBackend()` (neither event
  carries a request otherwise), since a CLI (e.g. scheduler task) or frontend-triggered
  change has no editor to show a notice to. The message text/title are proper LLL:
  labels (`notice.fileContentChanged`/`notice.fileContentChanged.title` in
  `locallang_db.xlf`), resolved via `LanguageService::sL()` (`$GLOBALS['LANG']`, same
  pattern as `AiMetadataBadgeFactory`).
  **Must use `FlashMessageQueue::NOTIFICATION_QUEUE`, not the default queue.** The
  Filelist "replace file" action is an AJAX endpoint
  (`EXT:backend`'s `ResourceController::replaceResourceAction()`) - right after
  dispatching `AfterFileReplacedEvent`, that same method drains the *default* flash
  message queue (`getMessageQueueByIdentifier()` with no identifier -
  `FlashMessageQueue::FLASHMESSAGE_QUEUE`) and repackages everything it finds there
  into one new message of its own (`implode("\n", ...)` over all queued messages),
  titled from its own `ajax.success` label and defaulting to `ContextualFeedbackSeverity::OK`.
  A message enqueued into the default queue there gets glued onto core's own "File X
  was replaced with Y" text as plain text and loses its own title/severity entirely -
  this is exactly what happened before this fix (confirmed by the user's bug report).
  `NOTIFICATION_QUEUE` is a separate queue that controller action never touches;
  `ModuleTemplate::dispatchNotificationMessages()` renders it as its own toast
  (`@typo3/backend/notification.js`) on the next full backend page render, title/
  severity intact. Tests must read flash messages back from
  `getMessageQueueByIdentifier(FlashMessageQueue::NOTIFICATION_QUEUE)`, not the
  default identifier, or `getAllMessagesAndFlush()` on the wrong queue always
  returns empty.

## Frontend integration

**No longer "renders nothing by itself"** - that was the original design, deliberately
reversed by explicit user request (2026-08-06): the extension now ships opinionated
default markup/CSS/icons and wires itself into every content element automatically.
The low-level building blocks (`AiLabelProcessor`, `RecordMetadataViewHelper`/
`FileMetadataViewHelper` handing `AiMetadata` through to Fluid, no decision DTO) are
unchanged and still the right things to reach for when the automatic path below doesn't
apply - only the "nothing renders by default" part of the old design is gone. An earlier
attempt built a whole `AiLabelDecision`/`AiLabelService` layer modeled on a wiki.txt spec
the user never asked for - that's still explicitly rejected; the current opinionated
frontend (below) was requested directly in this conversation, not resurrected from that
spec.

- **`Resources/Private/Partials/AiLabel.html`**: the actual markup - resolves
  `AiMetadata` via `<ailabel:recordMetadata>`/`<ailabel:fileMetadata>` (same ViewHelpers
  as always), renders nothing unless `flagged`, outputs one of the 8 bundled
  `Resources/Public/Icons/ai_{generated,modified}_{black,white}{,_transparent}.svg` via
  `f:image` (deliberately not `ac:svg`/`b13/assetcollector`, to avoid a new hard
  dependency - the user explicitly asked for `f:image`), and ships its own
  `<f:asset.css>` block. `variant` argument (default `black`) picks the icon color/opacity
  set.
- **`Resources/Private/Partials/DropIn/After/All.html`**: overrides
  `EXT:fluid_styled_content`'s own `Partials/DropIn/After/All.html` - an extension
  point Core ships intentionally empty (just an `<f:comment>` placeholder), rendered
  by `Layouts/Default.html`'s `<f:render section="After" optional="true">` fallback
  after every content element's main output. Adds exactly one line,
  `<f:render partial="AiLabel" arguments="{record: data}" />`. **Deliberately not** a copy of
  the whole `Layouts/Default.html` (an earlier version of this feature did that, to
  extend the "Footer" section instead) - overriding this already-empty, dedicated
  extension point needs no Core-Layout duplication at all, and the opt-out for a
  project that already calls `AiLabel` manually (e.g. `srh-edu`, ~60 templates outside
  any Footer/After section) is now just re-overriding this same one file with the
  empty placeholder restored, at a higher `partialRootPaths` priority - no
  `layoutRootPaths` entry needed on either side. See README.md, "Upgrading an
  existing project with manual AiLabel calls".
- **`Configuration/Sets/AiLabel/setup.typoscript`** (+ `config.yaml`, `name: b13/ai-label`):
  registers the Partials directory into `lib.contentElement.partialRootPaths` at
  priority `1550` - high enough to win over `EXT:fluid_styled_content`'s own `.10`,
  low enough that a project can still out-prioritize it (see README.md's two override
  sections). `Configuration/TypoScript/setup.typoscript` (classic, extension root) just
  `@import`s the Set's file, so both registration paths share one source of truth -
  same pattern as `b13/b13-baseconfig` (see its `Configuration/TypoScript/setup.typoscript`
  and `Configuration/Sets/b13-baseconfig/`).
  **Deliberately not** `ExtensionManagementUtility::addTypoScriptSetup()` in
  `ext_localconf.php` - an earlier version of this feature used that (attractive on paper:
  merges into `defaultTypoScript_setup` and `defaultTypoScript_setup.siteSets`
  unconditionally, no "Include static template" selection needed anywhere). It does not
  reliably end up in a Site-Set-based project's actual resolved TypoScript in practice -
  confirmed by integration-testing against a real b13 project (`veridos`): the registered
  `partialRootPaths.1550` entry was simply absent from the search path Fluid actually used
  (visible directly in the `InvalidTemplateResourceException`'s list of tried paths), even
  after a full cache flush.
  **Listing a Set as a `dependencies` entry in `config.yaml` alone does not pull in its
  `setup.typoscript` either** - also confirmed the hard way against the same project:
  adding `b13/ai-label` to `site_veridos`'s Set dependencies (nothing else) left
  `lib.contentElement.partialRootPaths.1550` just as absent as the `addTypoScriptSetup()`
  attempt did, even though `site:sets:list`/`site:show` correctly resolved and displayed
  the dependency. TypoScript setup content only actually merges in once something
  explicitly `@import`s the file by path - this is why `site_veridos`'s own
  `setup.typoscript` explicitly imports `b13-baseconfig`, `seo`, `picture`, `solr`, `form`,
  etc. one by one, despite all of them also being listed under `dependencies`. So a
  consuming project needs **both**: `b13/ai-label` under its own Set's `dependencies`
  (bookkeeping/discoverability) **and** an explicit
  `@import 'EXT:ai_label/Configuration/TypoScript/setup.typoscript'` line in its own
  `setup.typoscript` (the line that actually merges the TypoScript in) - see README.md's
  "Frontend integration" intro for both.
Both ViewHelpers (`render(): ?AiMetadata`) return the `AiMetadata` object directly when
used inline (`{ailabel:recordMetadata(record: data)}`), but return `null` when the
optional `as` argument is used to assign a variable directly (same convention as
`f:variable` - a standalone tag's return value gets string-cast into the output stream,
and `AiMetadata` has no `__toString()`, so it must not "leak" that way; `null` casts to
`''` same as an explicit empty string would, so this is behaviorally identical to the
original `string|AiMetadata`/`return ''` signature, just a more honest type).

## Optional dependencies

`typo3/cms-filelist` is `require-dev` only - `MarkFlaggedFilesInFileList` only
references `ProcessFileListActionsEvent` as a method-parameter type hint, which PHP
resolves lazily (only when actually invoked). If filelist isn't installed, the class
still loads fine and the listener is simply dormant (event never fires).

`typo3/cms-fluid-styled-content` is `require-dev` only too - the automatic DropIn
integration (`Configuration/Sets/AiLabel/setup.typoscript` overriding
`lib.contentElement.partialRootPaths`) is pure TypoScript, no PHP class dependency
anywhere. Without fluid_styled_content installed, `lib.contentElement` itself doesn't
exist, so the override is simply inert - the rest of the extension (ViewHelpers,
DataProcessor, backend badges) works standalone. `ext_emconf.php` already only lists
it under `suggests`, not `depends`; composer.json now matches that.

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
- **CI (`.github/workflows/ci.yml`) runs the matrix against both TYPO3 versions**, and
  phpstan needs a *second*, separate config for v13: `Build/phpstan13.neon` (level 5,
  same `Classes` path) plus `Build/phpstan13-baseline.neon` - the baseline exists because
  `Classes/Legacy/*`'s early-return guard (`if ($typo3Version->getMajorVersion() < 14) return;`)
  doesn't stop phpstan from statically analyzing the *v14* classes' references to
  v14-only core APIs (`ComponentFactory`, `ProcessFileListActionsEvent::getRequest()`/
  `setAction()`, etc.) that genuinely don't exist when `composer require typo3/cms-backend:^13.4`
  is installed - `Build/phpstan.neon`'s exclusion of `Classes/Legacy/` only handles the
  reverse case. Run both locally when touching anything version-split:
  ```
  php -d memory_limit=2G .Build/bin/phpstan analyse -c Build/phpstan13.neon
  ```
- **CSV fixture format is easy to get wrong**: the table name must be on its own line
  (first column only), *then* a separate line with a leading empty column + field names,
  *then* data rows (leading empty column). Table name + field names on the same line
  (e.g. `tt_content,uid,pid,tx_ailabel_metadata`) parses "successfully" but silently
  produces zero imported rows and a barely-related PHP warning deep in `DataSet.php` -
  this bit us once, see `git log` around the CSV-format fix. Reference format:
  ```
  tt_content
  ,uid,pid,tx_ailabel_metadata
  ,1,1,"{""origin"":1,""reviewed_by"":0,""reviewed_timestamp"":0}"
  ```
  `\NULL` (backslash prefix) is the special literal for SQL NULL - bare `NULL` is a
  4-character string literal.
- **Keep fixture CSVs down to only the columns a test actually needs.** Input fixtures
  (`importCSVDataSet()`) only need enough to satisfy real DB constraints (e.g. `pid`)
  plus whatever starting state the test cares about (`tx_ailabel_metadata`) - our test
  backend user is always admin, so page/table permission fields (`perms_*` on `pages`,
  etc.) are never actually checked and can be dropped. Result fixtures
  (`assertCSVDataSet()`) only need `uid` (to match the row) plus the column(s) the test
  is actually verifying - columns like `header`/`CType`/`tstamp` that the operation also
  touches don't need to be asserted just because DataHandler happened to write them,
  unless the test is specifically about that column.
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
  `$GLOBALS['BE_USER']`/`$GLOBALS['LANG']` set up (same as `AiMetaDataHandlerHookTest`) or
  `getBackendUser()`/`getLanguageService()` throw `TypeError` on the `null` global. The
  inherited `defaultFieldWizard` (`otherLanguageContent`, `defaultLanguageDifferences`)
  reads `$this->data['processedTca']['columns'][$fieldName]` unconditionally, so that key
  must be present too (real TCA config, e.g.
  `$GLOBALS['TCA']['tt_content']['columns']['tx_ailabel_origin']`) even though the test
  itself never touches TCA directly - a missing `processedTca` key triggers PHP warnings,
  not a hard failure, so it's easy to miss. `'inlineStructure' => []` is needed too -
  `SelectSingleElement::render()`'s inline-uniqueness check reads
  `$this->data['inlineStructure']` via `InlineStackProcessor`, and while it's guarded by
  `$this->data['isInlineChild'] ?? false` being falsy first, an entirely missing array
  key still surfaces as a warning on some PHP/TYPO3 patch combinations - same
  "easy to miss, only a warning" trap as `processedTca`.
- Testing backend-vs-frontend-vs-CLI branching (`AfterFileContentChangedListenerTest`): build
  `(new TYPO3\CMS\Core\Http\ServerRequest())->withAttribute('applicationType',
  SystemEnvironmentBuilder::REQUESTTYPE_BE)` (or `REQUESTTYPE_FE`) and assign it to
  `$GLOBALS['TYPO3_REQUEST']` - `ApplicationType::fromRequest()` reads that one request
  attribute, nothing else. Assert flash messages via
  `$this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessagesAndFlush()`
  (also clears the queue, so each test starts clean without needing its own `setUp()`
  reset). `ResourceFactory::getFileObject()` needs `$GLOBALS['BE_USER']` set (storage
  permission checks in `StorageRepository`) - a request alone isn't enough, unlike
  `new FileReference([...])` used elsewhere (see `FileMetadataViewHelperTest`), which
  never touches `StorageRepository`.

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
