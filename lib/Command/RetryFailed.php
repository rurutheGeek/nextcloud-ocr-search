<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Command;

use OCA\OcrSearch\Db\IndexStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RetryFailed extends Command {
	public function __construct(
		private IndexStore $store,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('ocr_search:retry-failed')
			->setDescription('Put the images that failed back into the queue');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln($this->store->retryFailed() . ' queued again');
		return 0;
	}
}
