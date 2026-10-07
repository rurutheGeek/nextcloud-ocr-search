<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Tests\Unit;

use OCA\OcrSearch\Service\Normalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase {
	private Normalizer $normalizer;

	protected function setUp(): void {
		$this->normalizer = new Normalizer();
	}

	public static function equivalents(): array {
		return [
			'full width and case' => ['ＡＢＣ１２３', 'abc123'],
			'half width katakana' => ['ﾎﾟｹﾓﾝ', 'ポケモン'],
			'lost voicing mark' => ['ケームを早くやめる', 'ゲームを早くやめる'],
			'kanji read as katakana' => ['ポケモンのカだね', 'ポケモンの力だね'],
			'traditional form' => ['內容と產業', '内容と産業'],
			'simplified form' => ['东京', '東京'],
			'small kana' => ['くるつている', 'くるっている'],
			'spaces between Japanese' => ['うん　これが いわタイプ', 'うんこれがいわタイプ'],
			'line break inside a sentence' => ["勘弁してほし\nいよ", '勘弁してほしいよ'],
			'accents' => ['Café', 'cafe'],
			'yen sign width' => ['￥1,280', '¥1,280'],
		];
	}

	#[DataProvider('equivalents')]
	public function testFoldsToTheSameForm(string $a, string $b): void {
		$this->assertSame($this->normalizer->normalize($b), $this->normalizer->normalize($a));
	}

	public function testKeepsSpacesBetweenLatinWords(): void {
		$this->assertSame('total amount 1280', $this->normalizer->normalize("Total  Amount\n1280"));
	}

	public function testDistinctTextStaysDistinct(): void {
		$this->assertNotSame($this->normalizer->normalize('領収書'), $this->normalizer->normalize('請求書'));
	}

	public function testSplitsQueryIntoTerms(): void {
		$this->assertSame(['receipt', 'コーヒー'], $this->normalizer->terms("  Receipt\u{3000}コーヒー receipt "));
		$this->assertSame([], $this->normalizer->terms('   '));
	}
}
