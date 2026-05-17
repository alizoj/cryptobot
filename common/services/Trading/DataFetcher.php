<?php

declare(strict_types=1);

namespace common\services\Trading;

use Yii;

/**
 * Thin orchestration layer over the existing standalone PHP fetcher
 * scripts under common/data/. Each step is shelled out via the same
 * PHP binary that's running this code; output is captured and returned.
 *
 * Keeping the underlying scripts as the source of truth means the data
 * format used by the simulator and the live bot stay identical.
 *
 * Steps (in order):
 *   1. fetch_bybit_data.php           — daily + 1h + 4h candles
 *   2. fetch_bybit_derivatives.php    — funding + open interest
 *   3. fetch_macro_dominance.php      — BTC.D / total mcap
 *   4. fetch_fng.php                  — Fear & Greed
 */
final class DataFetcher
{
    private string $dataDir;

    public function __construct(?string $dataDir = null)
    {
        $this->dataDir = $dataDir ?? Yii::getAlias('@common/data');
    }

    /**
     * Run all fetchers in order, stopping at the first failure.
     *
     * @return list<array{step:string,ok:bool,exitCode:int,output:string,seconds:float}>
     */
    public function refreshAll(): array
    {
        return [
            $this->runStep('prices',      'fetch_bybit_data.php'),
            $this->runStep('derivatives', 'fetch_bybit_derivatives.php'),
            $this->runStep('dominance',   'fetch_macro_dominance.php'),
            $this->runStep('fng',         'fetch_fng.php'),
        ];
    }

    public function refreshPrices(): array
    {
        return [$this->runStep('prices', 'fetch_bybit_data.php')];
    }

    public function refreshDerivatives(): array
    {
        return [$this->runStep('derivatives', 'fetch_bybit_derivatives.php')];
    }

    public function refreshMacro(): array
    {
        return [
            $this->runStep('dominance', 'fetch_macro_dominance.php'),
            $this->runStep('fng',       'fetch_fng.php'),
        ];
    }

    /**
     * @return array{step:string,ok:bool,exitCode:int,output:string,seconds:float}
     */
    private function runStep(string $label, string $scriptBasename, array $args = []): array
    {
        $script = $this->dataDir . DIRECTORY_SEPARATOR . $scriptBasename;
        if (!is_file($script)) {
            return [
                'step'     => $label,
                'ok'       => false,
                'exitCode' => 1,
                'output'   => "script not found: $script",
                'seconds'  => 0.0,
            ];
        }
        $phpBin = PHP_BINARY ?: 'php';
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg((string)$a);
        }
        $cmd .= ' 2>&1';

        $started = microtime(true);
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $elapsed = microtime(true) - $started;
        return [
            'step'     => $label,
            'ok'       => $code === 0,
            'exitCode' => $code,
            'output'   => implode("\n", $out),
            'seconds'  => round($elapsed, 2),
        ];
    }

    /**
     * Persist a small checkpoint (last successful refresh + per-step status).
     */
    public function recordCheckpoint(array $stepResults): void
    {
        $payload = [
            'ts'    => time(),
            'utc'   => gmdate('c'),
            'steps' => array_map(static function (array $r): array {
                return [
                    'step'     => $r['step'],
                    'ok'       => $r['ok'],
                    'exitCode' => $r['exitCode'],
                    'seconds'  => $r['seconds'],
                    'tail'     => substr($r['output'], -400),
                ];
            }, $stepResults),
        ];
        file_put_contents(Paths::fetchState(), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
