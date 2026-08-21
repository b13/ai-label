<?php

declare(strict_types=1);

namespace B13\AiLabel\Pagination;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use TYPO3\CMS\Core\Pagination\AbstractPaginator;

final class DemandedArrayPaginator extends AbstractPaginator
{
    private array $paginatedItems;
    private int $allCount;

    public function __construct(
        array $items,
        int $currentPageNumber = 1,
        int $itemsPerPage = 25,
        int $allCount = 0,
    ) {
        $this->paginatedItems = $items;
        $this->allCount = $allCount;
        $this->setCurrentPageNumber($currentPageNumber);
        $this->setItemsPerPage($itemsPerPage);
        $this->updateInternalState();
    }

    public function getPaginatedItems(): iterable
    {
        return $this->paginatedItems;
    }

    protected function updatePaginatedItems(int $itemsPerPage, int $offset): void
    {
        // Already sliced by the caller before construction. Nothing to do here.
    }

    protected function getTotalAmountOfItems(): int
    {
        return $this->allCount;
    }

    protected function getAmountOfItemsOnCurrentPage(): int
    {
        return count($this->paginatedItems);
    }
}
