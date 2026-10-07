<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Settings;

use OCA\OcrSearch\Db\IndexStore;
use OCA\OcrSearch\Service\Settings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Settings\IDelegatedSettings;

class Admin implements IDelegatedSettings {
	public function __construct(
		private Settings $settings,
		private IndexStore $store,
		private IRequest $request,
		private IURLGenerator $url,
		private IL10N $l,
	) {
	}

	public function getForm(): TemplateResponse {
		return new TemplateResponse('ocr_search', 'admin', [
			'ocr_url' => $this->settings->url(),
			'token_set' => $this->settings->token() !== '',
			'max_side' => $this->settings->maxSide(),
			'mime_types' => implode(', ', $this->settings->mimeTypes()),
			'min_size' => $this->settings->minSize(),
			'max_size' => $this->settings->maxSize(),
			'counts' => $this->store->counts(),
			'status' => (string)$this->request->getParam('ocr_search', ''),
			'save_url' => $this->url->linkToRoute('ocr_search.settings.save'),
		]);
	}

	public function getSection(): string {
		return 'ocr_search';
	}

	public function getPriority(): int {
		return 50;
	}

	public function getName(): ?string {
		return $this->l->t('OCR Search');
	}

	public function getAuthorizedAppConfig(): array {
		return ['ocr_search' => ['/.*/']];
	}
}
