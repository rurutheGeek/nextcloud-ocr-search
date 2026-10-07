<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Tests\Integration;

use OCA\OcrSearch\Db\IndexStore;
use OCA\OcrSearch\Service\Indexer;
use OCA\OcrSearch\Service\OcrRejected;
use OCA\OcrSearch\Service\OcrUnavailable;
use OCP\IDBConnection;
use OCP\Server;

class IndexerTest extends AppTestCase {
	public function testUploadQueuesTheImageForTheBackgroundJob(): void {
		$file = $this->image('alice', 'listener');
		$row = $this->store->get($file->getId());
		$this->assertNotNull($row);
		$this->assertSame(IndexStore::PENDING, (int)$row['status']);
		$this->assertSame(IndexStore::LIVE, (int)$row['priority']);
	}

	public function testOtherFilesAreNotQueued(): void {
		$file = $this->rootFolder->getUserFolder('alice')->newFile('notes-' . uniqid() . '.txt', str_repeat('x', 8192));
		$this->assertNull($this->store->get($file->getId()));
	}

	public function testRecognisedTextIsStoredAndNormalised(): void {
		$file = $this->image('alice', 'done');
		$this->recognises(['ケームを早く', 'やめるように']);
		$stats = $this->indexer->process(0, 0, true);
		$this->assertSame(1, $stats['done']);
		$row = $this->store->get($file->getId());
		$this->assertSame(IndexStore::DONE, (int)$row['status']);
		$this->assertSame("ケームを早く\nやめるように", $row['text']);
		$this->assertSame('ケームを早くやめるように', $row['normalized']);
		$this->assertSame($file->getEtag(), $row['etag']);
		$this->assertCount(2, json_decode($row['lines'], true));
	}

	public function testStoppedServiceCostsNoAttemptAndStopsTheRun(): void {
		$first = $this->image('alice', 'down', 'a.jpg');
		$second = $this->image('alice', 'down', 'b.jpg');
		$this->client->expects($this->once())->method('recognise')
			->willThrowException(new OcrUnavailable('connection refused'));
		$stats = $this->indexer->process(0, 0, true);
		$this->assertSame('connection refused', $stats['unavailable']);
		foreach ([$first, $second] as $file) {
			$row = $this->store->get($file->getId());
			$this->assertSame(IndexStore::PENDING, (int)$row['status']);
			$this->assertSame(0, (int)$row['attempts']);
			$this->assertSame(0, (int)$row['next_attempt']);
		}
	}

	public function testRejectedImageBacksOffAndFailsAfterFiveAttempts(): void {
		$file = $this->image('alice', 'bad');
		$this->client->method('recognise')->willThrowException(new OcrRejected('not an image'));
		for ($attempt = 1; $attempt <= Indexer::MAX_ATTEMPTS; $attempt++) {
			$before = time();
			$this->indexer->process(0, 0, true);
			$row = $this->store->get($file->getId());
			$this->assertSame($attempt, (int)$row['attempts']);
			$this->assertSame('not an image', $row['last_error']);
			if ($attempt < Indexer::MAX_ATTEMPTS) {
				$this->assertSame(IndexStore::PENDING, (int)$row['status']);
				$this->assertGreaterThanOrEqual($before + Indexer::backoff($attempt), (int)$row['next_attempt']);
				// Not due yet: another run leaves it alone.
				$this->assertSame(0, array_sum(array_slice($this->indexer->process(0, 0, true), 0, 4)));
				$this->makeDue($file->getId());
			}
		}
		$this->assertSame(IndexStore::FAILED, (int)$this->store->get($file->getId())['status']);
		$this->assertSame(1, $this->store->retryFailed());
		$row = $this->store->get($file->getId());
		$this->assertSame(IndexStore::PENDING, (int)$row['status']);
		$this->assertSame(0, (int)$row['attempts']);
	}

	public function testBackoffGrows(): void {
		$this->assertSame([300, 1200, 4800, 19200], array_map(Indexer::backoff(...), [1, 2, 3, 4]));
	}

	public function testMarkerFileExcludesTheFolderAndEverythingBelow(): void {
		$file = $this->image('alice', 'private/deeper');
		$private = $this->rootFolder->getUserFolder('alice')->get('private');
		if (!$private->nodeExists(Indexer::EXCLUDE_MARKER)) {
			$private->newFile(Indexer::EXCLUDE_MARKER, '');
		}
		$this->client->expects($this->never())->method('recognise');
		$stats = $this->indexer->process(0, 0, true);
		$this->assertSame(1, $stats['skipped']);
		$this->assertSame(IndexStore::SKIPPED, (int)$this->store->get($file->getId())['status']);
	}

	public function testTinyFilesAreSkipped(): void {
		$folder = $this->rootFolder->getUserFolder('alice');
		$name = 'icon-' . uniqid() . '.png';
		$file = $folder->newFile($name, 'tiny');
		$this->client->expects($this->never())->method('recognise');
		$this->indexer->process(0, 0, true);
		$this->assertSame(IndexStore::SKIPPED, (int)$this->store->get($file->getId())['status']);
	}

	public function testDeletedFileLeavesTheQueue(): void {
		$file = $this->image('alice', 'gone');
		$id = $file->getId();
		$file->delete();
		// Bypass the trash bin view: the row must disappear once the file id is gone.
		Server::get(IDBConnection::class)->getQueryBuilder()->delete('filecache')
			->where('fileid = ' . $id)->executeStatement();
		$this->assertSame(1, $this->store->purgeOrphans());
		$this->assertNull($this->store->get($id));
	}

	public function testBulkIndexQueuesAsBacklogAndSkipsUnchangedFiles(): void {
		$file = $this->image('alice', 'bulk');
		$this->store->delete($file->getId());
		$folder = $this->rootFolder->getUserFolder('alice')->get('bulk');
		$this->assertSame(['queued' => 1, 'unchanged' => 0], $this->indexer->enqueueFolder($folder, false));
		$this->assertSame(IndexStore::BACKLOG, (int)$this->store->get($file->getId())['priority']);

		// The background job leaves the backlog to the occ command.
		$this->recognises(['bulk text']);
		$this->assertSame(0, $this->indexer->process(0, 0, true)['done']);
		$this->assertSame(1, $this->indexer->process(0, 0, false)['done']);

		$this->assertSame(['queued' => 0, 'unchanged' => 1], $this->indexer->enqueueFolder($folder, false));
		$this->assertSame(['queued' => 1, 'unchanged' => 0], $this->indexer->enqueueFolder($folder, true));
	}

	public function testChangeDuringRecognitionKeepsTheFileQueued(): void {
		$file = $this->image('alice', 'race');
		$this->client->method('recognise')->willReturnCallback(function () use ($file) {
			// The file is written again while the service is working on it.
			$this->store->enqueue($file->getId(), IndexStore::LIVE);
			return ['width' => 1, 'height' => 1, 'lines' => [['text' => 'old', 'box' => [], 'score' => 1.0]]];
		});
		$this->indexer->process(0, 1, true);
		$row = $this->store->get($file->getId());
		$this->assertSame(IndexStore::PENDING, (int)$row['status']);
		$this->assertNull($row['text']);
	}

	public function testLimitsStopTheRun(): void {
		$this->image('alice', 'limit', 'a.jpg');
		$this->image('alice', 'limit', 'b.jpg');
		$this->recognises(['x']);
		$this->assertSame(1, $this->indexer->process(0, 1, true)['done']);
		$this->assertSame(1, $this->store->counts()['pending']);
	}

	private function makeDue(int $fileId): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update(IndexStore::TABLE)->set('next_attempt', $qb->createNamedParameter(0))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))->executeStatement();
	}
}
