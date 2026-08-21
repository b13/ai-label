<?php

declare(strict_types=1);

namespace B13\AiLabel\Backend;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Repository\AiLabelDemand;
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * Builds the asc/desc sort URLs per sortable column, preserving all active
 * filters. Coupled directly to AiLabelDemand rather than a shared interface:
 * this extension has exactly one sortable listing, so a generic abstraction
 * would be speculative.
 */
final class SortUrlBuilder
{
    public function __construct(
        private readonly UriBuilder $uriBuilder,
    ) {
    }

    /**
     * @return array<string, array{ascUrl: string, descUrl: string, active: bool, direction: string}>
     */
    public function build(AiLabelDemand $demand, string $route): array
    {
        $filterParams = [];
        foreach ($demand->getParameters() as $key => $value) {
            $filterParams['demand[' . $key . ']'] = $value;
        }

        $sortUrls = [];
        foreach (AiLabelDemand::getOrderFields() as $field) {
            $isActive = $demand->getOrderField() === $field;
            $sortUrls[$field] = [
                'ascUrl' => (string)$this->uriBuilder->buildUriFromRoute($route, array_merge($filterParams, [
                    'orderField' => $field,
                    'orderDirection' => AiLabelDemand::ORDER_ASCENDING,
                ])),
                'descUrl' => (string)$this->uriBuilder->buildUriFromRoute($route, array_merge($filterParams, [
                    'orderField' => $field,
                    'orderDirection' => AiLabelDemand::ORDER_DESCENDING,
                ])),
                'active' => $isActive,
                'direction' => $isActive ? $demand->getOrderDirection() : '',
            ];
        }
        return $sortUrls;
    }
}
