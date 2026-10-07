<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Command;

use OCA\OcrSearch\Db\IndexStore;
use OCA\OcrSearch\Service\OcrClient;
use OCA\OcrSearch\Service\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Status extends Command {
	public function __construct(
		private IndexStore $store,
		private OcrClient $client,
		private Settings $settings,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('ocr_search:status')
			->setDescription('Show the state of the index and of the OCR service')
			->addOption('output', null, InputOption::VALUE_REQUIRED, 'plain or json', 'plain');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$status = $this->store->counts() + [
			'configured' => $this->settings->configured(),
			'service' => $this->client->healthy(),
		];
		if ($input->getOption('output') === 'json') {
			$output->writeln(json_encode($status));
			return 0;
		}
		foreach ($status as $key => $value) {
			$output->writeln($key . ': ' . (is_bool($value) ? ($value ? 'yes' : 'no') : $value));
		}
		return 0;
	}
}
