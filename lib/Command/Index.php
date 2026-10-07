<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Command;

use OCA\OcrSearch\Service\Indexer;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Index extends Command {
	public function __construct(
		private Indexer $indexer,
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('ocr_search:index')
			->setDescription('Queue existing images for recognition (run ocr_search:process afterwards)')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only the files of this user (repeatable); all users when omitted')
			->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Only this folder inside the user files, e.g. /Photos')
			->addOption('force', null, InputOption::VALUE_NONE, 'Queue images again even when they are indexed and unchanged');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$path = (string)($input->getOption('path') ?? '');
		$force = (bool)$input->getOption('force');
		$total = ['queued' => 0, 'unchanged' => 0];
		$failed = false;
		$handle = function (IUser $user) use ($path, $force, $output, &$total, &$failed): void {
			try {
				$folder = $this->rootFolder->getUserFolder($user->getUID());
				if ($path !== '' && $path !== '/') {
					$folder = $folder->get($path);
				}
				if (!$folder instanceof Folder) {
					throw new \RuntimeException('not a folder');
				}
				$stats = $this->indexer->enqueueFolder($folder, $force);
			} catch (\Throwable $e) {
				$output->writeln("<error>{$user->getUID()}: {$e->getMessage()}</error>");
				$failed = true;
				return;
			}
			$output->writeln("{$user->getUID()}: {$stats['queued']} queued, {$stats['unchanged']} unchanged");
			$total['queued'] += $stats['queued'];
			$total['unchanged'] += $stats['unchanged'];
		};
		$users = $input->getOption('user');
		if ($users === []) {
			$this->userManager->callForSeenUsers($handle);
		} else {
			foreach ($users as $uid) {
				$user = $this->userManager->get($uid);
				if ($user === null) {
					$output->writeln("<error>Unknown user: $uid</error>");
					$failed = true;
					continue;
				}
				$handle($user);
			}
		}
		$output->writeln("Total: {$total['queued']} queued, {$total['unchanged']} unchanged");
		return $failed ? 1 : 0;
	}
}
