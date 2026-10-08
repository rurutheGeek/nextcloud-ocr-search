<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Migration;

use OCA\OcrSearch\Service\Settings;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Stores the OCR service token as a sensitive value. */
class ProtectToken implements IRepairStep {
	public function __construct(
		private Settings $settings,
	) {
	}

	public function getName(): string {
		return 'Protect the OCR service token';
	}

	public function run(IOutput $output): void {
		if ($this->settings->protectToken()) {
			$output->info('The token is now stored as a sensitive value');
		}
	}
}
