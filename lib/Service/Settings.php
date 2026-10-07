<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Service;

use OCA\OcrSearch\AppInfo\Application;
use OCP\IAppConfig;

/** Typed access to the app configuration and its defaults. */
class Settings {
	public const DEFAULT_MIME_TYPES = 'image/jpeg,image/png,image/webp,image/gif,image/bmp,image/tiff,image/heic,image/heif';
	public const DEFAULT_MAX_SIDE = 1024;
	public const DEFAULT_MIN_SIZE = 4096;
	public const DEFAULT_MAX_SIZE = 31457280;

	public function __construct(
		private IAppConfig $config,
	) {
	}

	public function url(): string {
		return rtrim(trim($this->config->getValueString(Application::APP_ID, 'ocr_url', '')), '/');
	}

	public function token(): string {
		return trim($this->config->getValueString(Application::APP_ID, 'ocr_token', ''));
	}

	public function configured(): bool {
		return $this->url() !== '' && $this->token() !== '';
	}

	public function maxSide(): int {
		return max(256, min(4096, $this->int('max_side', self::DEFAULT_MAX_SIDE)));
	}

	/** @return string[] */
	public function mimeTypes(): array {
		$value = $this->config->getValueString(Application::APP_ID, 'mime_types', self::DEFAULT_MIME_TYPES);
		return array_values(array_filter(array_map(
			static fn (string $item): string => strtolower(trim($item)),
			explode(',', $value),
		)));
	}

	public function minSize(): int {
		return max(0, $this->int('min_size', self::DEFAULT_MIN_SIZE));
	}

	public function maxSize(): int {
		return max(1, $this->int('max_size', self::DEFAULT_MAX_SIZE));
	}

	public function set(string $key, string $value): void {
		$this->config->setValueString(Application::APP_ID, $key, $value);
	}

	public function delete(string $key): void {
		$this->config->deleteKey(Application::APP_ID, $key);
	}

	public function get(string $key, string $default = ''): string {
		return $this->config->getValueString(Application::APP_ID, $key, $default);
	}

	// Values are stored as strings so that `occ config:app:set` works without
	// a type flag.
	private function int(string $key, int $default): int {
		$value = trim($this->config->getValueString(Application::APP_ID, $key, ''));
		return ctype_digit($value) ? (int)$value : $default;
	}
}
