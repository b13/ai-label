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

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

// Decides whether the overview module's site filter is worth offering at all -
// pointless with only one site in the installation, or when the current editor's
// webmounts only ever reach one of several. isInWebMount() already returns the
// webmount page id for an admin (all sites) without a real permission check, so
// this stays correct for both admins and restricted editors for free.
final class SiteAccessResolver
{
    public function __construct(private readonly SiteFinder $siteFinder)
    {
    }

    public function shouldOfferSiteFilter(BackendUserAuthentication $backendUser): bool
    {
        return count($this->siteFinder->getAllSites()) > 1
            && count($this->getAccessibleSites($backendUser)) > 1;
    }

    /** @return list<Site> */
    private function getAccessibleSites(BackendUserAuthentication $backendUser): array
    {
        return array_values(array_filter(
            $this->siteFinder->getAllSites(),
            static fn (Site $site): bool => $backendUser->isInWebMount($site->getRootPageId()) !== null
        ));
    }
}
