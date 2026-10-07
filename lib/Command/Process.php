<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Command;

use OCA\OcrSearch\Service\Indexer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Process extends Command {
	/** Exit code when the OCR service could not be used; the queue is intact. */
	public const UNAVAILABLE = 2;

	public function __construct(
		private Indexer $indexer,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('ocr_search:process')
			->setDescription('Recognise queued images, including the backlog from ocr_search:index')
			->addOption('max-runtime', 't', InputOption::VALUE_REQUIRED, 'Stop after this many seconds (0 = until the queue is empty)', '0')
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Stop after this many files (0 = no limit)', '0');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$stats = $this->indexer->process(
			max(0, (int)$input->getOption('max-runtime')),
			max(0, (int)$input->getOption('limit')),
			false,
			static function (int $fileId, string $outcome, string $detail) use ($output): void {
				if ($output->isVerbose()) {
					$output->writeln("$fileId $outcome $detail");
				}
			},
		);
		$output->writeln("{$stats['done']} recognised, {$stats['skipped']} skipped, {$stats['retry']} to retry, {$stats['failed']} failed");
		if ($stats['unavailable'] !== null) {
			$output->writeln("<error>OCR service unavailable: {$stats['unavailable']}</error>");
			return self::UNAVAILABLE;
		}
		return 0;
	}
}
