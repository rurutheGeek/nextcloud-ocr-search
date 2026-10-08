<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Service;

/**
 * Folds text into the form that is stored in the index and searched.
 *
 * The same folding is applied to recognised text and to search terms, so it
 * only has to be consistent, not reversible. It absorbs the mistakes OCR makes
 * most often on Japanese text: width and case differences, lost voicing marks
 * (ゲ read as ケ), old or Chinese forms of a kanji (內 for 内), and characters
 * that look the same in print (力 and カ).
 *
 * Whitespace and punctuation are dropped altogether. Recognition returns one
 * entry per line of the picture, so where a line ends says nothing about the
 * text, and punctuation is the part OCR gets wrong most often. A phrase copied
 * from the recognised text therefore matches however it was wrapped.
 */
class Normalizer {
	/** @var array<string, string>|null */
	private ?array $map = null;

	public function normalize(string $text): string {
		if (class_exists(\Normalizer::class)) {
			// Compatibility decomposition: full width becomes half width and
			// voiced kana split into base + combining mark, removed below.
			$text = \Normalizer::normalize($text, \Normalizer::FORM_KD) ?: $text;
		}
		$text = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;
		$text = mb_strtolower($text, 'UTF-8');
		$text = strtr($text, $this->map());
		return preg_replace('/[\s\p{Z}\p{P}\p{C}]+/u', '', $text) ?? $text;
	}

	/**
	 * Splits a search input into folded terms; all of them have to match.
	 *
	 * @return string[]
	 */
	public function terms(string $query): array {
		$terms = [];
		foreach (preg_split('/\s+/u', trim($query)) ?: [] as $part) {
			$term = $this->normalize($part);
			if ($term !== '') {
				$terms[] = $term;
			}
		}
		return array_values(array_unique($terms));
	}

	/** @return array<string, string> */
	private function map(): array {
		if ($this->map === null) {
			$this->map = [];
			foreach (preg_split('/\s+/u', trim(Variants::PAIRS)) ?: [] as $pair) {
				[$from, $to] = mb_str_split($pair, 1, 'UTF-8');
				$this->map[$from] = $to;
			}
		}
		return $this->map;
	}
}
