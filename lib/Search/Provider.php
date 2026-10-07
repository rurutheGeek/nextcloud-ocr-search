<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Search;

use OCA\OcrSearch\Db\IndexStore;
use OCA\OcrSearch\Service\Normalizer;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/** Unified search over the text recognised in images. */
class Provider implements IProvider {
	private const SNIPPET_BEFORE = 20;
	private const SNIPPET_LENGTH = 80;

	public function __construct(
		private IndexStore $store,
		private Normalizer $normalizer,
		private IRootFolder $rootFolder,
		private IUserMountCache $mountCache,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
	) {
	}

	public function getId(): string {
		return 'ocr_search';
	}

	public function getName(): string {
		return $this->l10n->t('Text in images');
	}

	public function getOrder(string $route, array $routeParameters): ?int {
		// Right after the file name results of the Files app.
		return str_starts_with($route, 'files.') ? -4 : 6;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$terms = $this->normalizer->terms($query->getTerm());
		$limit = max(1, $query->getLimit());
		$cursor = $query->getCursor();
		$before = is_numeric($cursor) ? (int)$cursor : null;

		// Narrow the candidates to the storages mounted for this user, then
		// let the user's own view of the file system decide. A storage can be
		// mounted only in part (a shared subfolder), so the second step is
		// what actually enforces access.
		$storageIds = [];
		foreach ($this->mountCache->getMountsForUser($user) as $mount) {
			$storageIds[$mount->getStorageId()] = true;
		}
		$storageIds = array_keys($storageIds);
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		$entries = [];
		$more = false;
		while (count($entries) < $limit) {
			$rows = $this->store->search($storageIds, $terms, $limit, $before);
			foreach ($rows as $row) {
				$before = $row['file_id'];
				$node = $userFolder->getFirstNodeById($row['file_id']);
				if ($node instanceof File) {
					$entries[] = $this->entry($node, $userFolder->getRelativePath($node->getPath()) ?? '', $row['text'], $query->getTerm());
					if (count($entries) === $limit) {
						break;
					}
				}
			}
			$more = count($rows) === $limit;
			if (!$more) {
				break;
			}
		}
		$name = $this->getName();
		return $more ? SearchResult::paginated($name, $entries, $before) : SearchResult::complete($name, $entries);
	}

	private function entry(File $file, string $path, string $text, string $term): SearchResultEntry {
		$entry = new SearchResultEntry(
			$this->urlGenerator->linkToRouteAbsolute('core.Preview.getPreviewByFileId', ['x' => 32, 'y' => 32, 'fileId' => $file->getId()]),
			$file->getName(),
			$this->snippet($text, $term),
			$this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('files.View.showFile', ['fileid' => $file->getId()])),
			'icon-picture',
		);
		$entry->addAttribute('fileId', (string)$file->getId());
		$entry->addAttribute('path', $path);
		return $entry;
	}

	/** The part of the recognised text around the first term that is found literally. */
	private function snippet(string $text, string $term): string {
		$flat = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
		$start = 0;
		foreach (preg_split('/\s+/u', trim($term)) ?: [] as $part) {
			$position = $part === '' ? false : mb_stripos($flat, $part, 0, 'UTF-8');
			if ($position !== false) {
				$start = max(0, $position - self::SNIPPET_BEFORE);
				break;
			}
		}
		$snippet = mb_substr($flat, $start, self::SNIPPET_LENGTH, 'UTF-8');
		return ($start > 0 ? '…' : '') . $snippet . ($start + self::SNIPPET_LENGTH < mb_strlen($flat, 'UTF-8') ? '…' : '');
	}
}
