<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Service;

use OCP\Http\Client\IClientService;

/** HTTP client for the OCR service in server/. */
class OcrClient {
	public function __construct(
		private IClientService $clientService,
		private Settings $settings,
	) {
	}

	/**
	 * @return array{width: int, height: int, lines: list<array{text: string, box: array, score: float}>}
	 * @throws OcrUnavailable the service is down, misconfigured or overloaded
	 * @throws OcrRejected the service cannot read this image
	 */
	public function recognise(string $image, string $mimeType): array {
		if (!$this->settings->configured()) {
			throw new OcrUnavailable('The OCR service is not configured');
		}
		try {
			$response = $this->clientService->newClient()->post(
				$this->settings->url() . '/v1/ocr?max_side=' . $this->settings->maxSide(),
				[
					'body' => $image,
					'headers' => [
						'Authorization' => 'Bearer ' . $this->settings->token(),
						'Content-Type' => $mimeType,
					],
					'timeout' => 180,
					'http_errors' => false,
					'nextcloud' => ['allow_local_address' => true],
				],
			);
		} catch (\Throwable $e) {
			throw new OcrUnavailable($e->getMessage(), 0, $e);
		}
		$status = $response->getStatusCode();
		$data = json_decode((string)$response->getBody(), true);
		if (in_array($status, [400, 413, 415, 422], true)) {
			throw new OcrRejected(is_array($data) ? (string)($data['error'] ?? "HTTP $status") : "HTTP $status");
		}
		if ($status !== 200 || !is_array($data) || !is_array($data['lines'] ?? null)) {
			throw new OcrUnavailable("The OCR service answered HTTP $status");
		}
		return [
			'width' => (int)($data['width'] ?? 0),
			'height' => (int)($data['height'] ?? 0),
			'lines' => array_values($data['lines']),
		];
	}

	public function healthy(): bool {
		if ($this->settings->url() === '') {
			return false;
		}
		try {
			$response = $this->clientService->newClient()->get($this->settings->url() . '/healthz', [
				'timeout' => 10,
				'http_errors' => false,
				'nextcloud' => ['allow_local_address' => true],
			]);
			return $response->getStatusCode() === 200;
		} catch (\Throwable) {
			return false;
		}
	}
}
