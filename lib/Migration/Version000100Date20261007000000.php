<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000100Date20261007000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('ocr_search_index')) {
			return null;
		}
		// One row per image: the queue entry and, once recognised, the text.
		$table = $schema->createTable('ocr_search_index');
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('status', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('priority', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('etag', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->addColumn('next_attempt', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('last_error', Types::STRING, ['notnull' => false, 'length' => 1000]);
		$table->addColumn('text', Types::TEXT, ['notnull' => false]);
		$table->addColumn('normalized', Types::TEXT, ['notnull' => false]);
		$table->addColumn('lines', Types::TEXT, ['notnull' => false]);
		$table->addColumn('width', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('height', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$table->setPrimaryKey(['file_id']);
		$table->addIndex(['status', 'priority', 'next_attempt'], 'ocr_search_queue');
		return $schema;
	}
}
