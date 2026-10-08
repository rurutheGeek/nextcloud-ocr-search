<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Command;

use OCA\OcrSearch\Db\IndexStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Clear extends Command {
	public function __construct(
		private IndexStore $store,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('ocr_search:clear')
			->setDescription('Delete all recognised text and the queue (the images are not touched)')
			->addOption('yes', 'y', InputOption::VALUE_NONE, 'Required: confirms that the index is to be deleted');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$input->getOption('yes')) {
			$output->writeln('<error>Nothing deleted. Pass --yes to delete the index.</error>');
			return 1;
		}
		$output->writeln($this->store->clear() . ' entries deleted');
		return 0;
	}
}
