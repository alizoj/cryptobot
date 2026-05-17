<?php

declare(strict_types=1);

namespace common\services\Trading;

use common\services\Trading\Fetcher\BybitDerivativesFetcher;
use common\services\Trading\Fetcher\BybitHttp;
use common\services\Trading\Fetcher\BybitPriceFetcher;
use common\services\Trading\Fetcher\DominanceFetcher;
use common\services\Trading\Fetcher\FetchResult;
use common\services\Trading\Fetcher\FngFetcher;
use common\services\Trading\Fetcher\InstrumentsIndex;
use common\services\Trading\Fetcher\LogTap;

/**
 * Top-level orchestrator over the four fetcher services.
 *
 * Each step is now a direct in-process service call (no more shelling out
 * to standalone PHP scripts) so we can stream progress, capture stats, and
 * stop on first failure cheaply.
 *
 * The standalone scripts under common/data/ stay in place for ops who
 * prefer to run them manually — both paths produce byte-identical files.
 */
final class DataFetcher
{
    private BybitHttp $http;
    private InstrumentsIndex $instruments;
    private BybitPriceFetcher $price;
    private BybitDerivativesFetcher $derivs;
    private DominanceFetcher $dominance;
    private FngFetcher $fng;

    public function __construct(
        ?BybitHttp $http = null,
        ?InstrumentsIndex $instruments = null,
        ?BybitPriceFetcher $price = null,
        ?BybitDerivativesFetcher $derivs = null,
        ?DominanceFetcher $dominance = null,
        ?FngFetcher $fng = null,
    ) {
        $this->http        = $http        ?? new BybitHttp();
        $this->instruments = $instruments ?? new InstrumentsIndex($this->http, Paths::instrumentsIndex());
        $this->price       = $price       ?? new BybitPriceFetcher($this->http, $this->instruments);
        $this->derivs      = $derivs      ?? new BybitDerivativesFetcher($this->http, $this->instruments);
        $this->dominance   = $dominance   ?? new DominanceFetcher();
        $this->fng         = $fng         ?? new FngFetcher();
    }

    /**
     * Run all four fetchers in order. `$progress` (if given) receives each
     * log line emitted by the underlying fetchers in real time.
     *
     * @return list<FetchResult>
     */
    public function refreshAll(array $opts = [], ?callable $progress = null): array
    {
        return [
            $this->step('prices',      fn(LogTap $t) => $this->price->run($opts['prices']      ?? [], $t), $progress),
            $this->step('derivatives', fn(LogTap $t) => $this->derivs->run($opts['derivatives'] ?? [], $t), $progress),
            $this->step('dominance',   fn(LogTap $t) => $this->dominance->run($opts['dominance'] ?? [], $t), $progress),
            $this->step('fng',         fn(LogTap $t) => $this->fng->run($opts['fng']            ?? [], $t), $progress),
        ];
    }

    /** @return list<FetchResult> */
    public function refreshPrices(array $opts = [], ?callable $progress = null): array
    {
        return [$this->step('prices', fn(LogTap $t) => $this->price->run($opts, $t), $progress)];
    }

    /** @return list<FetchResult> */
    public function refreshDerivatives(array $opts = [], ?callable $progress = null): array
    {
        return [$this->step('derivatives', fn(LogTap $t) => $this->derivs->run($opts, $t), $progress)];
    }

    /** @return list<FetchResult> */
    public function refreshMacro(array $opts = [], ?callable $progress = null): array
    {
        return [
            $this->step('dominance', fn(LogTap $t) => $this->dominance->run($opts['dominance'] ?? [], $t), $progress),
            $this->step('fng',       fn(LogTap $t) => $this->fng->run($opts['fng'] ?? [],       $t), $progress),
        ];
    }

    /** Persist a small checkpoint into common/data/trade_state/fetch_state.json. */
    public function recordCheckpoint(array $stepResults): void
    {
        $payload = [
            'ts'    => time(),
            'utc'   => gmdate('c'),
            'steps' => array_map(static function (FetchResult $r): array {
                $a = $r->toArray();
                $a['output'] = substr($a['output'], -400);
                return $a;
            }, $stepResults),
        ];
        file_put_contents(
            Paths::fetchState(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }

    /** @param callable(LogTap):FetchResult $run */
    private function step(string $label, callable $run, ?callable $forward): FetchResult
    {
        $tap = new LogTap($forward);
        try {
            $result = $run($tap);
        } catch (\Throwable $e) {
            $tap("FATAL: " . $e::class . ': ' . $e->getMessage());
            return new FetchResult(
                step: $label,
                ok: false,
                exitCode: 1,
                seconds: 0.0,
                summary: "exception: " . $e->getMessage(),
                stats: [],
                logTail: $tap->tail(40),
            );
        }
        // Attach the captured tail so callers can persist it for diagnostics.
        return new FetchResult(
            step: $result->step,
            ok: $result->ok,
            exitCode: $result->exitCode,
            seconds: $result->seconds,
            summary: $result->summary,
            stats: $result->stats,
            logTail: $tap->tail(40),
        );
    }
}
