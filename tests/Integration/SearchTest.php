<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Tests\Integration;

use OC\Files\SetupManager;
use OCA\OcrSearch\Controller\TextController;
use OCA\OcrSearch\Search\Provider;
use OCP\Constants;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Search\ISearchQuery;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;

class SearchTest extends AppTestCase {
	/** @return string[] file names found */
	private function search(string $uid, string $term, int $limit = 25, &$cursor = null): array {
		// A new request: the user's mounts are resolved again.
		Server::get(SetupManager::class)->tearDown();
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn($limit);
		$query->method('getCursor')->willReturn($cursor);
		$result = Server::get(Provider::class)
			->search(Server::get(IUserManager::class)->get($uid), $query)->jsonSerialize();
		$cursor = $result['cursor'];
		return array_map(static fn ($entry): string => $entry->jsonSerialize()['title'], $result['entries']);
	}

	private function index(string $uid, string $path, string $name, array $lines): \OCP\Files\File {
		$file = $this->image($uid, $path, $name);
		$client = $this->createMock(\OCA\OcrSearch\Service\OcrClient::class);
		$client->method('recognise')->willReturn(['width' => 1, 'height' => 1, 'lines' => array_map(
			static fn (string $text): array => ['text' => $text, 'box' => [], 'score' => 1.0], $lines)]);
		(new \OCA\OcrSearch\Service\Indexer(
			$this->store, $client, Server::get(\OCA\OcrSearch\Service\Normalizer::class),
			Server::get(\OCA\OcrSearch\Service\Settings::class), $this->rootFolder,
			Server::get(\Psr\Log\LoggerInterface::class),
		))->process(0, 0, true);
		return $file;
	}

	public function testFindsOwnImageByItsText(): void {
		$this->index('alice', 'search', 'receipt.jpg', ['スーパーマルエツ', 'コーヒー豆　￥1,280']);
		$this->assertSame(['receipt.jpg'], $this->search('alice', 'コーヒー豆'));
		$this->assertSame(['receipt.jpg'], $this->search('alice', 'マルエツ 1,280'));
		$this->assertSame([], $this->search('alice', 'マルエツ 紅茶'));
	}

	public function testOcrMistakesStillMatch(): void {
		$this->index('alice', 'search', 'note.jpg', ['ケームを早くやめるように', 'ポケモンの　カだね']);
		$this->assertSame(['note.jpg'], $this->search('alice', 'ゲームを早く'));
		$this->assertSame(['note.jpg'], $this->search('alice', 'ポケモンの力'));
	}

	public function testTextCopiedFromTheSidebarMatches(): void {
		$this->index('alice', 'search', 'chat.jpg', ['おいおい、', '毎年', 'クリスマスで何', '万使ってるよ。', '上限は、', '1万']);
		// Pasted into a one-line search field the line breaks are gone.
		$this->assertSame(['chat.jpg'], $this->search('alice', 'おいおい、毎年クリスマスで何万使ってるよ。上限は、1万'));
		$this->assertSame(['chat.jpg'], $this->search('alice', 'おいおい、 毎年 クリスマスで何'));
		$this->assertSame(['chat.jpg'], $this->search('alice', '上限は1万'));
	}

	public function testLikeWildcardsAreLiteral(): void {
		$this->index('alice', 'search', 'plain.jpg', ['nothing special']);
		$this->assertSame([], $this->search('alice', '%'));
		$this->assertSame([], $this->search('alice', 'n_thing'));
		$this->assertSame([], $this->search('alice', 'n%l'));
	}

	public function testOtherUsersFilesAreNotFoundUntilShared(): void {
		$file = $this->index('alice', 'secret', 'passport.jpg', ['旅券番号 TK1234567']);
		$other = $this->index('alice', 'secret', 'unshared.jpg', ['旅券番号 ZZ7654321']);
		$this->assertSame([], $this->search('bob', '旅券番号'));
		$this->assertSame(404, $this->text('bob', $file->getId())->getStatus());

		$shares = Server::get(IManager::class);
		$share = $shares->newShare();
		$share->setNode($file)->setShareType(IShare::TYPE_USER)->setSharedBy('alice')
			->setSharedWith('bob')->setPermissions(Constants::PERMISSION_READ);
		$share = $shares->createShare($share);
		try {
			// Both files live on alice's storage, which is now mounted for bob
			// in part: only the shared one may come back.
			$this->assertSame(['passport.jpg'], $this->search('bob', '旅券番号'));
			$this->assertSame('旅券番号 TK1234567', $this->text('bob', $file->getId())->getData()['text']);
			$this->assertSame(404, $this->text('bob', $other->getId())->getStatus());
		} finally {
			$shares->deleteShare($share);
		}
		$this->assertSame([], $this->search('bob', '旅券番号'));
	}

	public function testPagination(): void {
		foreach (['p1.jpg', 'p2.jpg', 'p3.jpg'] as $name) {
			$this->index('alice', 'pages', $name, ['ページ送りの確認']);
		}
		$cursor = null;
		$first = $this->search('alice', 'ページ送り', 2, $cursor);
		$this->assertSame(['p3.jpg', 'p2.jpg'], $first);
		$this->assertSame(['p1.jpg'], $this->search('alice', 'ページ送り', 2, $cursor));
	}

	private function text(string $uid, int $fileId): \OCP\AppFramework\Http\JSONResponse {
		Server::get(SetupManager::class)->tearDown();
		return (new TextController('ocr_search', $this->createMock(IRequest::class), $this->store, $this->rootFolder, $uid))
			->show($fileId);
	}
}
