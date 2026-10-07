<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Listener;

use OCA\OcrSearch\Db\IndexStore;
use OCA\OcrSearch\Service\Indexer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use Psr\Log\LoggerInterface;

/**
 * Queues new and changed images. Recognition itself happens later in the
 * background job, so an upload only pays for one small insert.
 *
 * @implements IEventListener<Event>
 */
class NodeListener implements IEventListener {
	public function __construct(
		private Indexer $indexer,
		private IndexStore $store,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof NodeCopiedEvent) {
				$node = $event->getTarget();
			} elseif ($event instanceof NodeCreatedEvent || $event instanceof NodeWrittenEvent) {
				$node = $event->getNode();
			} else {
				return;
			}
			if ($this->indexer->candidate($node)) {
				$this->store->enqueue($node->getId(), IndexStore::LIVE);
			}
		} catch (\Throwable $e) {
			// Indexing must never break an upload.
			$this->logger->warning('Could not queue a file for OCR: ' . $e->getMessage(), ['app' => 'ocr_search', 'exception' => $e]);
		}
	}
}
