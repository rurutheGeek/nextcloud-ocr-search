<?php

declare(strict_types=1);

// The tests run inside a Nextcloud server with the app installed in
// apps/ocr_search or custom_apps/ocr_search (see tests/run.sh).
require_once __DIR__ . '/../../../lib/base.php';

\OC::$composerAutoloader->addPsr4('OCA\\OcrSearch\\Tests\\', __DIR__ . '/');
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('ocr_search');
