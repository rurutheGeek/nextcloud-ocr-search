<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** The queue and the text index; one row per image, keyed by file id. */
class IndexStore {
	public const TABLE = 'ocr_search_index';

	public const PENDING = 0;
	public const DONE = 1;
	public const FAILED = 2;
	public const SKIPPED = 3;

	/** Uploads and edits: handled by the background job. */
	public const LIVE = 1;
	/** Bulk indexing: handled only by `occ ocr_search:process`. */
	public const BACKLOG = 0;

	public const STATUS_NAMES = [
		self::PENDING => 'pending',
		self::DONE => 'done',
		self::FAILED => 'failed',
		self::SKIPPED => 'skipped',
	];

	/** How long a claimed row stays hidden from other workers. */
	private const CLAIM_SECONDS = 600;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Puts a file into the queue, or back into it when it is already known.
	 *
	 * Never throws on a duplicate: this runs inside upload requests.
	 */
	public function enqueue(int $fileId, int $priority): void {
		$inserted = $this->db->insertIgnoreConflict(self::TABLE, [
			'file_id' => $fileId,
			'status' => self::PENDING,
			'priority' => $priority,
			'updated_at' => time(),
		]);
		if ($inserted > 0) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter(self::PENDING, IQueryBuilder::PARAM_INT))
			->set('attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('next_attempt', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('last_error', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		if ($priority === self::LIVE) {
			$qb->set('priority', $qb->createNamedParameter(self::LIVE, IQueryBuilder::PARAM_INT));
		}
		$qb->executeStatement();
	}

	/** @return array<string, mixed>|null */
	public function get(int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	/**
	 * Takes the next due file out of the queue for this worker.
	 *
	 * @return array{file_id: int, attempts: int, claim: int}|null the claim
	 *   value has to be passed back when the outcome is stored
	 */
	public function claim(bool $liveOnly): ?array {
		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'attempts')->from(self::TABLE)
			->where($qb->expr()->eq('status', $qb->createNamedParameter(self::PENDING, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('next_attempt', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('priority', 'DESC')
			->addOrderBy('next_attempt', 'ASC')
			->addOrderBy('file_id', 'ASC')
			->setMaxResults(20);
		if ($liveOnly) {
			$qb->andWhere($qb->expr()->eq('priority', $qb->createNamedParameter(self::LIVE, IQueryBuilder::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		$claim = $now + self::CLAIM_SECONDS;
		foreach ($rows as $row) {
			$update = $this->db->getQueryBuilder();
			$update->update(self::TABLE)
				->set('next_attempt', $update->createNamedParameter($claim, IQueryBuilder::PARAM_INT))
				->where($update->expr()->eq('file_id', $update->createNamedParameter((int)$row['file_id'], IQueryBuilder::PARAM_INT)))
				->andWhere($update->expr()->eq('status', $update->createNamedParameter(self::PENDING, IQueryBuilder::PARAM_INT)))
				->andWhere($update->expr()->lte('next_attempt', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
			// Another worker may have taken the row in the meantime.
			if ($update->executeStatement() === 1) {
				return ['file_id' => (int)$row['file_id'], 'attempts' => (int)$row['attempts'], 'claim' => $claim];
			}
		}
		return null;
	}

	/**
	 * Stores the outcome for a claimed row.
	 *
	 * Does nothing when the file was queued again while it was being
	 * recognised: the row then stays pending for the new content.
	 *
	 * @param array<string, mixed> $values
	 */
	public function finish(int $fileId, int $claim, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::PENDING, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('next_attempt', $qb->createNamedParameter($claim, IQueryBuilder::PARAM_INT)));
		foreach ($values as $column => $value) {
			$type = match (true) {
				$value === null => IQueryBuilder::PARAM_NULL,
				is_int($value) => IQueryBuilder::PARAM_INT,
				default => IQueryBuilder::PARAM_STR,
			};
			$qb->set($column, $qb->createNamedParameter($value, $type));
		}
		$qb->executeStatement();
	}

	public function delete(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** Removes rows whose file no longer exists. */
	public function purgeOrphans(): int {
		$files = $this->db->getQueryBuilder();
		$files->select('fileid')->from('filecache');
		$qb = $this->db->getQueryBuilder();
		return $qb->delete(self::TABLE)
			->where($qb->expr()->notIn('file_id', $qb->createFunction($files->getSQL())))
			->executeStatement();
	}

	public function retryFailed(): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter(self::PENDING, IQueryBuilder::PARAM_INT))
			->set('attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('next_attempt', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('status', $qb->createNamedParameter(self::FAILED, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @return array<string, int> number of rows per status name */
	public function counts(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('status')->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE)->groupBy('status')->executeQuery();
		$counts = array_fill_keys(array_values(self::STATUS_NAMES), 0);
		while ($row = $result->fetch()) {
			$counts[self::STATUS_NAMES[(int)$row['status']] ?? 'unknown'] = (int)$row['total'];
		}
		$result->closeCursor();
		return $counts;
	}

	/**
	 * Recognised files on the given storages whose text contains every term,
	 * newest file id first.
	 *
	 * The storage filter only narrows the candidates; the caller still has to
	 * check each file against the user's view of the file system.
	 *
	 * @param int[] $storageIds
	 * @param string[] $terms already normalised
	 * @return list<array{file_id: int, text: string}>
	 */
	public function search(array $storageIds, array $terms, int $limit, ?int $before): array {
		if ($storageIds === [] || $terms === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.file_id', 'i.text')
			->from(self::TABLE, 'i')
			->innerJoin('i', 'filecache', 'f', $qb->expr()->eq('i.file_id', 'f.fileid'))
			->where($qb->expr()->eq('i.status', $qb->createNamedParameter(self::DONE, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('f.storage', $qb->createNamedParameter($storageIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('i.file_id', 'DESC')
			->setMaxResults($limit);
		foreach ($terms as $term) {
			$qb->andWhere($qb->expr()->like(
				'i.normalized',
				$qb->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%'),
			));
		}
		if ($before !== null) {
			$qb->andWhere($qb->expr()->lt('i.file_id', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = ['file_id' => (int)$row['file_id'], 'text' => (string)$row['text']];
		}
		$result->closeCursor();
		return $rows;
	}
}
