<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return 'ocr_search';
	}

	public function getName(): string {
		return $this->l->t('OCR Search');
	}

	public function getPriority(): int {
		return 50;
	}

	public function getIcon(): string {
		return $this->url->imagePath('ocr_search', 'app.svg');
	}
}
