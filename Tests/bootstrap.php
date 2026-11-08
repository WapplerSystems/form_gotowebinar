<?php

// Root autoloader of the project the package is installed in, plus the test
// namespace (autoload-dev of a dependency is not part of it).
$loader = require __DIR__ . '/../../../vendor/autoload.php';
$loader->addPsr4('WapplerSystems\\FormGotowebinar\\', __DIR__ . '/../Classes');
$loader->addPsr4('WapplerSystems\\FormGotowebinar\\Tests\\', __DIR__);
