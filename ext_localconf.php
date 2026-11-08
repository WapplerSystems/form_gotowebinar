<?php

defined('TYPO3') or die();

// Webinar list from GoTo, so a page view does not wait for the API each time.
// File backend: no cache table has to be created on deployment.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['gotowebinar'] ??= [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend' => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
    'groups' => ['system'],
];

$GLOBALS['TYPO3_CONF_VARS']['MAIL']['templateRootPaths'][1760] = 'EXT:goto_webinar/Resources/Private/Templates/Email/';
