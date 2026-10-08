<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Migration;

use OCA\OcrSearch\Db\IndexStore;
use OCA\OcrSearch\Service\Normalizer;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Brings the stored searchable text in line with the current normalisation
 * rules after an update, so that images do not have to be recognised again.
 */
class Renormalize implements IRepairStep {
	public function __construct(
		private IndexStore $store,
		private Normalizer $normalizer,
	) {
	}

	public function getName(): string {
		return 'Update the searchable text of recognised images';
	}

	public function run(IOutput $output): void {
		$changed = $this->store->renormalize($this->normalizer->normalize(...));
		$output->info("$changed images updated");
	}
}
