<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Service;

use OCA\OcrSearch\Db\IndexStore;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/** Decides which files are indexed and works through the queue. */
class Indexer {
	public const MAX_ATTEMPTS = 5;
	/** Putting this file into a folder keeps the folder and everything below it out of the index. */
	public const EXCLUDE_MARKER = '.noocr';

	public function __construct(
		private IndexStore $store,
		private OcrClient $client,
		private Normalizer $normalizer,
		private Settings $settings,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/** Cheap test used on every file event: an image type in a user's files. */
	public function candidate(Node $node): bool {
		return $node instanceof File
			&& preg_match('#^/[^/]+/files/#', $node->getPath()) === 1
			&& in_array(strtolower($node->getMimetype()), $this->settings->mimeTypes(), true);
	}

	/** @return string|null why the file is left out, null when it is indexed */
	public function exclusion(File $file): ?string {
		if (!$this->candidate($file)) {
			return 'not an indexed image type';
		}
		$size = $file->getSize();
		if ($size < $this->settings->minSize()) {
			return 'smaller than the minimum size';
		}
		if ($size > $this->settings->maxSize()) {
			return 'larger than the maximum size';
		}
		try {
			for ($folder = $file->getParent(); substr_count($folder->getPath(), '/') >= 2; $folder = $folder->getParent()) {
				if ($folder->nodeExists(self::EXCLUDE_MARKER)) {
					return 'excluded by ' . self::EXCLUDE_MARKER;
				}
			}
		} catch (\Throwable) {
			// The top of a mount without a readable parent ends the walk.
		}
		return null;
	}

	/**
	 * Queues the images below a folder.
	 *
	 * @return array{queued: int, unchanged: int}
	 */
	public function enqueueFolder(Folder $folder, bool $force): array {
		$stats = ['queued' => 0, 'unchanged' => 0];
		foreach ($folder->searchByMime('image') as $node) {
			if (!$this->candidate($node)) {
				continue;
			}
			$row = $this->store->get($node->getId());
			if (!$force && $row !== null && (
				(int)$row['status'] === IndexStore::PENDING
				|| ((int)$row['status'] !== IndexStore::FAILED && $row['etag'] === $node->getEtag())
			)) {
				$stats['unchanged']++;
				continue;
			}
			$this->store->enqueue($node->getId(), IndexStore::BACKLOG);
			$stats['queued']++;
		}
		return $stats;
	}

	/**
	 * Works through the queue until it is empty, a limit is reached or the
	 * OCR service stops answering.
	 *
	 * @param int $maxSeconds 0 = no time limit
	 * @param int $maxFiles 0 = no limit
	 * @param callable(int, string, string): void|null $report file id, outcome, detail
	 * @return array{done: int, failed: int, skipped: int, retry: int, unavailable: ?string}
	 */
	public function process(int $maxSeconds, int $maxFiles, bool $liveOnly, ?callable $report = null): array {
		$stats = ['done' => 0, 'failed' => 0, 'skipped' => 0, 'retry' => 0, 'unavailable' => null];
		$deadline = $maxSeconds > 0 ? time() + $maxSeconds : null;
		$handled = 0;
		while (($deadline === null || time() < $deadline) && ($maxFiles === 0 || $handled < $maxFiles)) {
			$item = $this->store->claim($liveOnly);
			if ($item === null) {
				break;
			}
			$handled++;
			try {
				[$outcome, $detail] = $this->handle($item);
			} catch (OcrUnavailable $e) {
				// Not this file's fault: give it back untouched and stop, so a
				// stopped service costs nothing and no attempt is used up.
				$this->store->finish($item['file_id'], $item['claim'], ['next_attempt' => 0]);
				$stats['unavailable'] = $e->getMessage();
				$this->logger->warning('OCR service unavailable: ' . $e->getMessage(), ['app' => 'ocr_search']);
				break;
			}
			$stats[$outcome]++;
			if ($report !== null) {
				$report($item['file_id'], $outcome, $detail);
			}
		}
		return $stats;
	}

	/**
	 * @param array{file_id: int, attempts: int, claim: int} $item
	 * @return array{0: string, 1: string} outcome and detail
	 */
	private function handle(array $item): array {
		$fileId = $item['file_id'];
		$file = $this->rootFolder->getFirstNodeById($fileId);
		if (!$file instanceof File) {
			$this->store->delete($fileId);
			return ['skipped', 'file is gone'];
		}
		$reason = $this->exclusion($file);
		if ($reason !== null) {
			$this->store->finish($fileId, $item['claim'], [
				'status' => IndexStore::SKIPPED, 'etag' => $file->getEtag(), 'last_error' => $reason,
				'text' => null, 'normalized' => null, 'lines' => null, 'next_attempt' => 0,
			]);
			return ['skipped', $reason];
		}
		$etag = $file->getEtag();
		try {
			$result = $this->client->recognise($file->getContent(), $file->getMimetype());
		} catch (OcrUnavailable $e) {
			throw $e;
		} catch (\Throwable $e) {
			// Unreadable storage or an image the service refuses.
			$attempts = $item['attempts'] + 1;
			$final = $attempts >= self::MAX_ATTEMPTS;
			$this->store->finish($fileId, $item['claim'], [
				'status' => $final ? IndexStore::FAILED : IndexStore::PENDING,
				'attempts' => $attempts,
				'next_attempt' => $final ? 0 : time() + self::backoff($attempts),
				'last_error' => mb_substr($e->getMessage(), 0, 1000),
			]);
			return [$final ? 'failed' : 'retry', $e->getMessage()];
		}
		$text = implode("\n", array_filter(array_map(
			static fn (array $line): string => trim((string)($line['text'] ?? '')),
			$result['lines'],
		), static fn (string $line): bool => $line !== ''));
		$this->store->finish($fileId, $item['claim'], [
			'status' => IndexStore::DONE,
			'etag' => $etag,
			'attempts' => 0,
			'next_attempt' => 0,
			'last_error' => null,
			'text' => $text,
			'normalized' => $this->normalizer->normalize($text),
			'lines' => json_encode($result['lines'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'width' => $result['width'],
			'height' => $result['height'],
		]);
		return ['done', (string)count($result['lines'])];
	}

	/** 5 min, 20 min, 80 min, 5 h 20 min. */
	public static function backoff(int $attempts): int {
		return 300 * (4 ** max(0, $attempts - 1));
	}
}
