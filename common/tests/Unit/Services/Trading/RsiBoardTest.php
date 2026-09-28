<?php

declare(strict_types=1);

namespace common\tests\Unit\Services\Trading;

use Codeception\Test\Unit;
use common\services\Trading\RsiBoard;
use Yii;

/**
 * RsiBoard builds from per-symbol history files in a temp dir and writes the
 * board to a temp state dir; nothing touches the real data.
 */
final class RsiBoardTest extends Unit
{
    private const DAY = 86_400_000;
    private const T0  = 1_700_000_000_000;

    private string $dir;
    private array $savedParams;

    protected function _before(): void
    {
        $this->dir = sys_get_temp_dir() . '/rsi_board_test_' . uniqid();
        mkdir($this->dir . '/history', 0775, true);
        $this->savedParams = Yii::$app->params['trading'] ?? [];
        Yii::$app->params['trading'] = [
            'historyDir' => $this->dir . '/history',
            'stateDir'   => $this->dir . '/state',
        ] + $this->savedParams;
    }

    protected function _after(): void
    {
        Yii::$app->params['trading'] = $this->savedParams;
        foreach (glob($this->dir . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/history');
        @rmdir($this->dir . '/state');
        @rmdir($this->dir);
    }

    public function testLoadIsNullBeforeFirstBuild(): void
    {
        $this->assertNull((new RsiBoard())->load());
    }

    public function testBuildWritesRowsForEachSymbol(): void
    {
        $this->writeSymbol('AAA', 30, 1.0);    // steadily rising
        $this->writeSymbol('BBB', 30, -1.0);   // steadily falling
        $this->writeSymbol('NEW', 5, 1.0);     // too little history for RSI(14)

        $sum = (new RsiBoard())->build();
        $this->assertSame(['symbols' => 2, 'computed' => 3, 'reused' => 0], array_diff_key($sum, ['seconds' => 0]));

        $board = (new RsiBoard())->load();
        $bySym = array_column($board['rows'], null, 'symbol');
        $this->assertSame(['AAA', 'BBB'], array_keys($bySym));
        $this->assertSame(100.0, $bySym['AAA']['rsi']['1d']);
        $this->assertSame(0.0, $bySym['BBB']['rsi']['1d']);
        $this->assertNull($bySym['AAA']['rsi']['15m'], 'missing interval yields null, not an error');
        $this->assertSame(129.0, $bySym['AAA']['price']);
        $this->assertSame(self::T0 + 29 * self::DAY, $board['data_ts']);
    }

    public function testRebuildOnlyRecomputesChangedFiles(): void
    {
        $this->writeSymbol('AAA', 30, 1.0);
        $this->writeSymbol('BBB', 30, -1.0);
        $this->writeSymbol('NEW', 5, 1.0);
        (new RsiBoard())->build();

        $sum = (new RsiBoard())->build();
        $this->assertSame(0, $sum['computed'], 'nothing changed — every row and the skip list reused');
        $this->assertSame(2, $sum['reused']);

        $this->writeSymbol('BBB', 31, -1.0);
        touch($this->dir . '/history/BBB.json', time() + 5);
        $sum = (new RsiBoard())->build();
        $this->assertSame(1, $sum['computed']);
        $this->assertSame(1, $sum['reused']);

        $sum = (new RsiBoard())->build(force: true);
        $this->assertSame(3, $sum['computed']);
    }

    public function testRemovedSymbolDropsOffTheBoard(): void
    {
        $this->writeSymbol('AAA', 30, 1.0);
        $this->writeSymbol('BBB', 30, 1.0);
        (new RsiBoard())->build();

        unlink($this->dir . '/history/BBB.json');
        (new RsiBoard())->build();
        $this->assertSame(['AAA'], array_column((new RsiBoard())->load()['rows'], 'symbol'));
    }

    /** Daily-only history: $n bars, close = 100 + i * $step. */
    private function writeSymbol(string $sym, int $n, float $step): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $c = (string)(100 + $i * $step);
            $rows[] = [(string)(self::T0 + $i * self::DAY), $c, $c, $c, $c, '1000', '0'];
        }
        file_put_contents(
            "{$this->dir}/history/{$sym}.json",
            json_encode(['history' => ['intervals' => ['D' => ['rows' => $rows]]]]),
        );
    }
}
