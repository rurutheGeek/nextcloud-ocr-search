<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Controller;

use OCA\OcrSearch\Db\IndexStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;

class TextController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IndexStore $store,
		private IRootFolder $rootFolder,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/** The recognised text of one image the current user can access. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/text/{fileId}')]
	public function show(int $fileId): JSONResponse {
		$node = $this->userId === null
			? null
			: $this->rootFolder->getUserFolder($this->userId)->getFirstNodeById($fileId);
		if (!$node instanceof File) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		$row = $this->store->get($fileId);
		if ($row === null) {
			return new JSONResponse(['status' => 'none', 'text' => '']);
		}
		$status = (int)$row['status'];
		return new JSONResponse([
			'status' => IndexStore::STATUS_NAMES[$status] ?? 'none',
			'text' => $status === IndexStore::DONE ? (string)$row['text'] : '',
		]);
	}
}
