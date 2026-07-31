<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Datahandler;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class AbstractDatahandler extends FunctionalTestCase
{
    protected ?DataHandler $dataHandler = null;

    protected ?BackendUserAuthentication $backendUser = null;

    // ai_label composer-requires typo3/cms-filelist (for the file list marker event
    // listener) - not part of testing-framework's default sysext set, so it must be
    // loaded explicitly or PackageCollection throws "depends on package filelist".
    protected array $coreExtensionsToLoad = [
        'filelist',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Fixed "now" so tstamp/crdate and the hook's reviewed_timestamp are deterministic.
        $GLOBALS['EXEC_TIME'] = 1440000000;
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $this->backendUser;
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $this->dataHandler = GeneralUtility::makeInstance(DataHandler::class);
    }
}
