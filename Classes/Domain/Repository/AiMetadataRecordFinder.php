<?php

declare(strict_types=1);

namespace B13\AiLabel\Domain\Repository;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Configuration\ApplicableTablesProvider;
use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use TYPO3\CMS\Backend\History\RecordHistory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

// Collects records across the applicable tables whose tx_ailabel_metadata marks them
// as flagged (AI-created or AI-modified). Not an Extbase repository, no persistence layer in
// use here - just a plain query helper, used by the overview module (site-wide)
// and MarkFlaggedPageInLayoutModule (single page, tt_content only).
//
// Workspace-aware: only ever shows what the current backend user would actually
// see for their active workspace - the live version (or its workspace-overlaid
// draft, if one exists), plus records that only exist as a brand new version in
// this workspace. Never leaks another workspace's drafts. BackendUtility::
// workspaceOL() itself already no-ops entirely if EXT:workspaces isn't loaded or
// the workspace is live (id 0), so this stays a no-op extra query in that case.
final class AiMetadataRecordFinder
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ApplicableTablesProvider $applicableTablesProvider,
        private readonly AiMetadataBadgeFactory $badgeFactory,
        private readonly Context $context,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly IconFactory $iconFactory,
    ) {
    }

    /** @return list<array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string}> */
    public function findFlaggedRecords(): array
    {
        $records = [];
        foreach ($this->applicableTablesProvider->getApplicableTables() as $table) {
            $records = array_merge($records, $this->findFlaggedRecordsForTable($table, null));
        }

        return $records;
    }

    /**
     * Only tt_content, only on this one page - used to fold "review required"/
     * "reviewed by X on Y" badges into the Page module's content element headers.
     *
     * @return list<array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string}>
     */
    public function findFlaggedContentElementsOnPage(int $pageId): array
    {
        if (!$this->applicableTablesProvider->isTableApplicable('tt_content')) {
            return [];
        }

        return $this->findFlaggedRecordsForTable('tt_content', $pageId);
    }

    /** @return list<array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string}> */
    private function findFlaggedRecordsForTable(string $table, ?int $pid): array
    {
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id');
        $isVersionable = $this->tcaSchemaFactory->has($table)
            && $this->tcaSchemaFactory->get($table)->hasCapability(TcaSchemaCapability::Workspace);

        $records = [];

        foreach ($this->findLiveRows($table, $pid, $isVersionable) as $row) {
            if ($isVersionable && $workspaceId > 0) {
                BackendUtility::workspaceOL($table, $row, $workspaceId);
                if ($row === false) {
                    // Moved away in this workspace - see workspaceOL()'s $unsetMovePointers.
                    continue;
                }
                if (VersionState::tryFrom($row['t3ver_state'] ?? 0) === VersionState::DELETE_PLACEHOLDER) {
                    // Deleted in this workspace - would disappear on publish, don't list it.
                    continue;
                }
            }

            $record = $this->buildRecord($table, $row);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        if ($isVersionable && $workspaceId > 0) {
            foreach ($this->findNewInWorkspace($table, $pid, $workspaceId) as $row) {
                $record = $this->buildRecord($table, $row);
                if ($record !== null) {
                    $records[] = $record;
                }
            }
        }

        return $records;
    }

    /** @return list<array<string, mixed>> */
    private function findLiveRows(string $table, ?int $pid, bool $isVersionable): array
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->isNotNull('tx_ailabel_metadata'));

        if ($pid !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)));
        }

        if ($isVersionable) {
            // Only the live records here - workspace drafts of them are added back in
            // via workspaceOL() in findFlaggedRecordsForTable(), scoped to the current workspace.
            $queryBuilder->andWhere($queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * Records that only exist as a brand new version inside this workspace - they have
     * no live counterpart yet, so findLiveRows()/workspaceOL() never surfaces them.
     *
     * @return list<array<string, mixed>>
     */
    private function findNewInWorkspace(string $table, ?int $pid, int $workspaceId): array
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->isNotNull('tx_ailabel_metadata'),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_state', $queryBuilder->createNamedParameter(VersionState::NEW_PLACEHOLDER->value, Connection::PARAM_INT))
            );

        if ($pid !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)));
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * Filters and sorts an already-fetched record list per $demand. Takes the
     * list rather than fetching it itself so the overview module's stats,
     * distinct-table filter options, and filtered listing can all be derived
     * from a single findFlaggedRecords() call instead of querying three times.
     *
     * Filtering/sorting happens in PHP, not SQL: tx_ailabel_metadata is a JSON
     * blob, and filtering on its decoded content isn't portable across
     * MySQL/SQLite/Postgres without per-database JSON path functions.
     *
     * @param list<array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string}> $records
     * @return list<array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string}>
     */
    public function filterAndSort(array $records, AiLabelDemand $demand): array
    {
        $filtered = array_values(array_filter($records, function (array $record) use ($demand): bool {
            if ($demand->hasTable() && $record['table'] !== $demand->getTable()) {
                return false;
            }
            if ($demand->hasOrigin()) {
                $matchesOrigin = $demand->getOrigin() === 'created'
                    ? $record['metadata']->isAiCreated()
                    : $record['metadata']->isAiModified();
                if (!$matchesOrigin) {
                    return false;
                }
            }
            if ($demand->hasReviewStatus()) {
                $isReviewed = $record['metadata']->isReviewed();
                if ($demand->getReviewStatus() === 'reviewed' && !$isReviewed) {
                    return false;
                }
                if ($demand->getReviewStatus() === 'required' && $isReviewed) {
                    return false;
                }
            }
            if ($demand->hasSearch() && stripos($this->searchableLabel($record), $demand->getSearch()) === false) {
                return false;
            }
            return true;
        }));

        usort($filtered, fn (array $a, array $b): int => $this->compareForSort($a, $b, $demand));

        return $filtered;
    }

    private function compareForSort(array $a, array $b, AiLabelDemand $demand): int
    {
        $result = match ($demand->getOrderField()) {
            'title' => strcasecmp($this->searchableLabel($a), $this->searchableLabel($b)),
            'author' => strcasecmp($a['author'], $b['author']),
            'origin' => $a['metadata']->getOrigin()->value <=> $b['metadata']->getOrigin()->value,
            'reviewed' => (int)$a['metadata']->isReviewed() <=> (int)$b['metadata']->isReviewed(),
            // Sorts by the speaking label actually shown in the table, not the
            // raw table name, e.g. "File metadata" vs "sys_file_metadata".
            default => strcasecmp($a['tableLabel'], $b['tableLabel']),
        };
        return $demand->getOrderDirection() === AiLabelDemand::ORDER_DESCENDING ? -$result : $result;
    }

    /**
     * @param array{tableLabel: string, uid: int, title: string} $record
     */
    private function searchableLabel(array $record): string
    {
        return $record['title'] !== '' ? $record['title'] : ($record['tableLabel'] . ' #' . $record['uid']);
    }

    /**
     * Site-wide counts across ALL flagged records, ignoring the current demand's filters.
     *
     * @param list<array{metadata: AiMetadata}> $records
     * @return array{total: int, created: int, modified: int, reviewRequired: int, reviewed: int}
     */
    public function calculateStatistics(array $records): array
    {
        $total = count($records);
        $created = 0;
        $reviewRequired = 0;
        foreach ($records as $record) {
            if ($record['metadata']->isAiCreated()) {
                $created++;
            }
            if (!$record['metadata']->isReviewed()) {
                $reviewRequired++;
            }
        }

        return [
            'total' => $total,
            'created' => $created,
            'modified' => $total - $created,
            'reviewRequired' => $reviewRequired,
            'reviewed' => $total - $reviewRequired,
        ];
    }

    /**
     * Filter-dropdown options: one per raw table actually present in $records,
     * labelled with that table's own speaking title (LLL:ctrl.title), not the
     * per-row/per-type label buildRecord() resolves, since a filter option
     * applies to the whole table, not one record's specific type.
     *
     * @param list<array{table: string}> $records
     * @return list<array{value: string, label: string}>
     */
    public function getDistinctTables(array $records): array
    {
        $tables = array_unique(array_column($records, 'table'));
        $options = array_map(
            fn (string $table): array => ['value' => $table, 'label' => $this->resolveTableTitle($table)],
            $tables,
        );
        usort($options, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        return $options;
    }

    /** @param array<string, mixed> $row */
    private function buildRecord(string $table, array $row): ?array
    {
        $metadata = AiMetadata::fromJsonString($row['tx_ailabel_metadata'] ?? null);
        if (!$metadata->isFlagged()) {
            return null;
        }

        return [
            'table' => $table,
            'uid' => (int)$row['uid'],
            'pid' => (int)$row['pid'],
            'title' => BackendUtility::getRecordTitle($table, $row),
            'metadata' => $metadata,
            'author' => $this->resolveAuthor($table, $row),
            // Resolves per-row overlays (hidden, workspace state, ...), same as
            // any other backend record listing. Built here rather than in the
            // Fluid template since IconFactory needs the actual DB row, which
            // the overview module's demand-filtering layer never carries.
            'icon' => $this->iconFactory->getIconForRecord($table, $row, IconSize::SMALL)->render(),
            // Editors see this, not the raw table name. Resolved per record
            // rather than per table since a type-specific title (if the table's
            // "types" configuration for this row's type defines one) takes
            // precedence over the table's own generic title.
            'tableLabel' => $this->resolveRecordTypeTitle($table, $row),
            // Same badge markup as everywhere else (form legend, layout module) -
            // built here instead of the Fluid template so the "review required" vs
            // "reviewed by X on Y" wording/color can't drift apart between the two.
            'reviewBadge' => $this->badgeFactory->getBadge($metadata),
        ];
    }

    /** @param array<string, mixed> $row */
    private function resolveAuthor(string $table, array $row): string
    {
        $recordHistory = GeneralUtility::makeInstance(RecordHistory::class);
        $ownerInformation = $recordHistory->getCreationInformationForRecord($table, $row);
        $ownerUid = (int)(is_array($ownerInformation) && ($ownerInformation['usertype'] ?? '') === 'BE' ? $ownerInformation['userid'] : 0);
        if ($ownerUid <= 0) {
            return '';
        }

        $creatorRecord = BackendUtility::getRecord('be_users', $ownerUid);
        return ($creatorRecord['realName'] ?? '') ?: ($creatorRecord['username'] ?? '') ?: '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveRecordTypeTitle(string $table, array $row): string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return $table;
        }

        $schema = $this->tcaSchemaFactory->get($table);
        $typeFieldConfiguration = $schema->getRawConfiguration()['type'] ?? null;
        if (is_string($typeFieldConfiguration) && $typeFieldConfiguration !== '') {
            $typeField = explode(':', $typeFieldConfiguration)[0];
            $typeValue = (string)($row[$typeField] ?? '');
            if ($typeValue !== '' && $schema->hasSubSchema($typeValue)) {
                $schema = $schema->getSubSchema($typeValue);
            }
        }

        return $this->resolveSchemaTitle($schema, $table);
    }

    private function resolveTableTitle(string $table): string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return $table;
        }

        return $this->resolveSchemaTitle($this->tcaSchemaFactory->get($table), $table);
    }

    private function resolveSchemaTitle(TcaSchema $schema, string $fallback): string
    {
        $title = $schema->getTitle(fn (string $reference): string => $this->getLanguageService()->sL($reference));
        return $title !== '' ? $title : $fallback;
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
