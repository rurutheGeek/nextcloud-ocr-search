<?php

declare(strict_types=1);

namespace OCA\OcrSearch\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\OcrSearch\Listener\LoadAdditionalScripts;
use OCA\OcrSearch\Listener\NodeListener;
use OCA\OcrSearch\Search\Provider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'ocr_search';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerSearchProvider(Provider::class);
		$context->registerEventListener(NodeCreatedEvent::class, NodeListener::class);
		$context->registerEventListener(NodeWrittenEvent::class, NodeListener::class);
		$context->registerEventListener(NodeCopiedEvent::class, NodeListener::class);
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadAdditionalScripts::class);
	}

	public function boot(IBootContext $context): void {
	}
}
