<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Controller;

use OCA\OcrSearch\Service\OcrClient;
use OCA\OcrSearch\Service\Settings;
use OCA\OcrSearch\Settings\Admin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class SettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private Settings $settings,
		private OcrClient $client,
		private IURLGenerator $url,
	) {
		parent::__construct($appName, $request);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'POST', url: '/settings')]
	public function save(
		string $ocrUrl = '',
		string $ocrToken = '',
		bool $clearToken = false,
		string $maxSide = '',
		string $mimeTypes = '',
		string $minSize = '',
		string $maxSize = '',
	): RedirectResponse {
		$this->settings->set('ocr_url', rtrim(trim($ocrUrl), '/'));
		if ($clearToken) {
			$this->settings->delete('ocr_token');
		} elseif (trim($ocrToken) !== '') {
			$this->settings->set('ocr_token', trim($ocrToken));
		}
		foreach (['max_side' => $maxSide, 'min_size' => $minSize, 'max_size' => $maxSize] as $key => $value) {
			if (ctype_digit(trim($value))) {
				$this->settings->set($key, trim($value));
			} else {
				$this->settings->delete($key);
			}
		}
		$types = implode(',', array_filter(array_map('trim', preg_split('/[\s,]+/', strtolower($mimeTypes)) ?: [])));
		if ($types === '') {
			$this->settings->delete('mime_types');
		} else {
			$this->settings->set('mime_types', $types);
		}
		$status = !$this->settings->configured() ? 'incomplete' : ($this->client->healthy() ? 'ok' : 'unreachable');
		return new RedirectResponse(
			$this->url->linkToRoute('settings.AdminSettings.index', ['section' => 'ocr_search'])
			. '?ocr_search=' . $status
		);
	}
}
