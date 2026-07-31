<?php

declare(strict_types=1);

namespace B13\AiLabel\Domain\Model;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

// Immutable value object for the ai_metadata JSON column - not an Extbase entity,
// there is no repository/persistence for this, DataHandler writes the column
// directly (see AiMetaDataHandlerHook).
//
// ai_metadata is a real TCA type=json column, so depending on where a caller got
// its data from, it's either a raw JSON string (e.g. a plain QueryBuilder fetch)
// or an already-decoded array (e.g. FormEngine's DatabaseEditRow, which runs
// BackendUtility::convertDatabaseRowValuesToPhp() on type=json columns). Callers
// know which one they have - use fromJsonString()/fromArray() accordingly, or
// plain `new self()` if there's no stored value at all yet.
final class AiMetadata
{
    private bool $aiCreated = false;
    private bool $aiModified = false;
    private int $reviewedBy = 0;
    private int $reviewedTimestamp = 0;

    public static function fromJsonString(?string $json): self
    {
        if ($json === null || $json === '') {
            return new self();
        }
        $decoded = json_decode($json, true);
        return self::fromArray(is_array($decoded) ? $decoded : null);
    }

    public static function fromArray(?array $data): self
    {
        $metadata = new self();
        if ($data === null) {
            return $metadata;
        }

        $metadata->aiCreated = (bool)($data['ai_created'] ?? false);
        $metadata->aiModified = (bool)($data['ai_modified'] ?? false);
        $metadata->reviewedBy = (int)($data['reviewed_by'] ?? 0);
        $metadata->reviewedTimestamp = (int)($data['reviewed_timestamp'] ?? 0);
        return $metadata;
    }

    public function isAiCreated(): bool
    {
        return $this->aiCreated;
    }

    public function isAiModified(): bool
    {
        return $this->aiModified;
    }

    public function isFlagged(): bool
    {
        return $this->aiCreated || $this->aiModified;
    }

    public function getReviewedBy(): int
    {
        return $this->reviewedBy;
    }

    public function isReviewed(): bool
    {
        return $this->reviewedBy > 0;
    }

    public function getReviewedTimestamp(): int
    {
        return $this->reviewedTimestamp;
    }

    public function withAiCreated(bool $aiCreated): self
    {
        $clone = clone $this;
        $clone->aiCreated = $aiCreated;
        return $clone;
    }

    public function withAiModified(bool $aiModified): self
    {
        $clone = clone $this;
        $clone->aiModified = $aiModified;
        return $clone;
    }

    public function withReviewedBy(int $reviewedBy): self
    {
        $clone = clone $this;
        $clone->reviewedBy = $reviewedBy;
        return $clone;
    }

    public function withReviewedTimestamp(int $reviewedTimestamp): self
    {
        $clone = clone $this;
        $clone->reviewedTimestamp = $reviewedTimestamp;
        return $clone;
    }

    /**
     * The value to assign to DataHandler's $fieldArray['ai_metadata'] - a plain
     * array, DataHandler/Doctrine encode it themselves for the json column.
     */
    public function toArray(): array
    {
        return [
            'ai_created' => (int)$this->aiCreated,
            'ai_modified' => (int)$this->aiModified,
            'reviewed_by' => $this->reviewedBy,
            'reviewed_timestamp' => $this->reviewedTimestamp,
        ];
    }
}
