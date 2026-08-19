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

use Psr\Http\Message\ServerRequestInterface;

/**
 * Filter/sort/pagination state for the AI Label overview module. Plain,
 * immutable value object parsed once from the request, then threaded
 * through the repository, SortUrlBuilder, and pagination base URL builder.
 */
final class AiLabelDemand
{
    public const ORDER_ASCENDING = 'asc';
    public const ORDER_DESCENDING = 'desc';
    private const DEFAULT_ORDER_FIELD = 'reviewed';

    /**
     * Records needing review bubble to the top by default. reviewed=false
     * sorts before reviewed=true ascending, which fits this module's whole
     * purpose better than an alphabetical table-name default would.
     */
    private const ORDER_FIELDS = ['table', 'title', 'author', 'origin', 'reviewed'];

    private const ORIGIN_VALUES = ['created', 'modified'];
    private const REVIEW_STATUS_VALUES = ['required', 'reviewed'];

    private int $limit = 25;

    public function __construct(
        private int $page = 1,
        private string $orderField = self::DEFAULT_ORDER_FIELD,
        private string $orderDirection = self::ORDER_ASCENDING,
        private string $table = '',
        private string $origin = '',
        private string $reviewStatus = '',
        private string $search = '',
    ) {
        if (!in_array($orderField, self::ORDER_FIELDS, true)) {
            $orderField = self::DEFAULT_ORDER_FIELD;
        }
        $this->orderField = $orderField;
        if (!in_array($orderDirection, [self::ORDER_ASCENDING, self::ORDER_DESCENDING], true)) {
            $orderDirection = self::ORDER_ASCENDING;
        }
        $this->orderDirection = $orderDirection;
        if (!in_array($origin, self::ORIGIN_VALUES, true)) {
            $this->origin = '';
        }
        if (!in_array($reviewStatus, self::REVIEW_STATUS_VALUES, true)) {
            $this->reviewStatus = '';
        }
        $this->page = max(1, $page);
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $page = (int)($request->getQueryParams()['page'] ?? $request->getParsedBody()['page'] ?? 1);
        $orderField = (string)($request->getQueryParams()['orderField'] ?? $request->getParsedBody()['orderField'] ?? self::DEFAULT_ORDER_FIELD);
        $orderDirection = (string)($request->getQueryParams()['orderDirection'] ?? $request->getParsedBody()['orderDirection'] ?? self::ORDER_ASCENDING);
        $demand = $request->getQueryParams()['demand'] ?? $request->getParsedBody()['demand'] ?? [];
        if (!is_array($demand)) {
            $demand = [];
        }

        return new self(
            $page,
            $orderField,
            $orderDirection,
            (string)($demand['table'] ?? ''),
            (string)($demand['origin'] ?? ''),
            (string)($demand['review_status'] ?? ''),
            trim((string)($demand['search'] ?? '')),
        );
    }

    /**
     * @return list<string>
     */
    public static function getOrderFields(): array
    {
        return self::ORDER_FIELDS;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getOrderField(): string
    {
        return $this->orderField;
    }

    public function getOrderDirection(): string
    {
        return $this->orderDirection;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function hasTable(): bool
    {
        return $this->table !== '';
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function hasOrigin(): bool
    {
        return $this->origin !== '';
    }

    public function getReviewStatus(): string
    {
        return $this->reviewStatus;
    }

    public function hasReviewStatus(): bool
    {
        return $this->reviewStatus !== '';
    }

    public function getSearch(): string
    {
        return $this->search;
    }

    public function hasSearch(): bool
    {
        return $this->search !== '';
    }

    public function hasConstraints(): bool
    {
        return $this->hasTable() || $this->hasOrigin() || $this->hasReviewStatus() || $this->hasSearch();
    }

    /**
     * Active filter parameters (not sorting/paging), reused by SortUrlBuilder
     * and the pagination base URL so neither one drops the current filter.
     *
     * @return array<string, string>
     */
    public function getParameters(): array
    {
        $parameters = [];
        if ($this->hasTable()) {
            $parameters['table'] = $this->table;
        }
        if ($this->hasOrigin()) {
            $parameters['origin'] = $this->origin;
        }
        if ($this->hasReviewStatus()) {
            $parameters['review_status'] = $this->reviewStatus;
        }
        if ($this->hasSearch()) {
            $parameters['search'] = $this->search;
        }
        return $parameters;
    }
}
