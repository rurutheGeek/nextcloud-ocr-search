<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Tests\Integration;

use OCA\OcrSearch\Db\IndexStore;
use OCA\OcrSearch\Service\Indexer;
use OCA\OcrSearch\Service\Normalizer;
use OCA\OcrSearch\Service\OcrClient;
use OCA\OcrSearch\Service\Settings;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

abstract class AppTestCase extends TestCase {
	protected IndexStore $store;
	protected IRootFolder $rootFolder;
	protected OcrClient&MockObject $client;
	protected Indexer $indexer;

	protected function setUp(): void {
		$this->store = Server::get(IndexStore::class);
		$this->rootFolder = Server::get(IRootFolder::class);
		$this->client = $this->createMock(OcrClient::class);
		$this->indexer = new Indexer(
			$this->store,
			$this->client,
			Server::get(Normalizer::class),
			Server::get(Settings::class),
			$this->rootFolder,
			Server::get(LoggerInterface::class),
		);
		$this->user('alice');
		$this->user('bob');
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete(IndexStore::TABLE)->executeStatement();
	}

	protected function user(string $uid): void {
		$manager = Server::get(IUserManager::class);
		if (!$manager->userExists($uid)) {
			$manager->createUser($uid, 'Long-test-password-' . $uid . '-4827');
		}
	}

	/** Creates (or replaces) a file that is large enough to be indexed. */
	protected function image(string $uid, string $path, string $name = 'photo.jpg'): File {
		$folder = $this->rootFolder->getUserFolder($uid);
		foreach (array_filter(explode('/', $path)) as $part) {
			$folder = $folder->nodeExists($part) ? $folder->get($part) : $folder->newFolder($part);
		}
		if ($folder->nodeExists($name)) {
			$folder->get($name)->delete();
		}
		return $folder->newFile($name, str_repeat('x', 8192));
	}

	/** @param string[] $lines */
	protected function recognises(array $lines): void {
		$this->client->method('recognise')->willReturn([
			'width' => 100,
			'height' => 50,
			'lines' => array_map(
				static fn (string $text): array => ['text' => $text, 'box' => [[0, 0], [9, 0], [9, 9], [0, 9]], 'score' => 0.9],
				$lines,
			),
		]);
	}
}
