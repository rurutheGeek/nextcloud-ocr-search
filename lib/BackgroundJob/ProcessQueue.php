<?php

declare(strict_types=1);

namespace OCA\OcrSearch\BackgroundJob;

use OCA\OcrSearch\Db\IndexStore;
use OCA\OcrSearch\Service\Indexer;
use OCA\OcrSearch\Service\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Recognises newly uploaded and changed images.
 *
 * The backlog queued by `occ ocr_search:index` is left to
 * `occ ocr_search:process`, so that the administrator decides when the bulk
 * load runs.
 */
class ProcessQueue extends TimedJob {
	private const MAX_SECONDS = 120;
	private const PURGE_INTERVAL = 86400;

	public function __construct(
		ITimeFactory $time,
		private Indexer $indexer,
		private IndexStore $store,
		private Settings $settings,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void {
		if ($this->settings->configured()) {
			$this->indexer->process(self::MAX_SECONDS, 0, true);
		}
		$now = $this->time->getTime();
		if ($now - (int)$this->settings->get('last_purge', '0') >= self::PURGE_INTERVAL) {
			$this->store->purgeOrphans();
			$this->settings->set('last_purge', (string)$now);
		}
	}
}
