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
// directly (see AiMetaDataHandlerHook). Decodes once in the constructor so callers
// don't have to poke at the raw array/JSON themselves.
final class AiMetadata
{
    private bool $aiCreated = false;
    private bool $aiModified = false;
    private int $reviewedBy = 0;
    private int $reviewedTimestamp = 0;

    public function __construct(?string $json)
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            return;
        }

        $this->aiCreated = (bool)($decoded['ai_created'] ?? false);
        $this->aiModified = (bool)($decoded['ai_modified'] ?? false);
        $this->reviewedBy = (int)($decoded['reviewed_by'] ?? 0);
        $this->reviewedTimestamp = (int)($decoded['reviewed_timestamp'] ?? 0);
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
