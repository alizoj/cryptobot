<?php

declare(strict_types=1);

namespace common\services\Trading\Fetcher;

/**
 * Cached Bybit V5 spot + linear instruments index. Used by both the price
 * fetcher (to discover the correct trading pair for a HashHedge ticker)
 * and the derivatives fetcher (to resolve the linear perp for funding/OI).
 *
 * Resolves the spot trading pair and the linear perp for a HashHedge ticker
 * (formerly the procedural fetch_instruments_index() / resolve_bybit_pair() /
 * resolve_linear_pair()).
 */
final class InstrumentsIndex
{
    /** @var array{spot: array<string,array<string,mixed>>, linear: array<string,array<string,mixed>>} */
    private array $index = ['spot' => [], 'linear' => []];

    private bool $loaded = false;

    public function __construct(
        private readonly BybitHttp $http,
        private readonly string $cachePath,
    ) {
    }

    /**
     * Refetch the disk cache once it is older than this. Without it, tickers
     * listed after the first run never resolve ("no bybit pair") until someone
     * refreshes the index by hand.
     */
    public const MAX_AGE_SEC = 86_400;

    /**
     * Load (or refetch) the index. Cached to disk (refreshed daily) and in
     * memory, so repeated resolves within a run are free.
     */
    public function ensureLoaded(bool $forceRefresh = false, ?callable $log = null): array
    {
        if ($this->loaded && !$forceRefresh) {
            return $this->index;
        }
        $cached = is_file($this->cachePath);
        if (!$forceRefresh && $cached && time() - (int)filemtime($this->cachePath) < self::MAX_AGE_SEC) {
            return $this->loadCache();
        }
        try {
            return $this->fetch($log);
        } catch (\Throwable $e) {
            if (!$cached) {
                throw $e;
            }
            $log && $log('Instruments index refresh failed (' . $e->getMessage() . ') — using the cached copy');
            return $this->loadCache();
        }
    }

    private function loadCache(): array
    {
        $this->index = json_decode((string)file_get_contents($this->cachePath), true, flags: JSON_THROW_ON_ERROR);
        $this->loaded = true;
        return $this->index;
    }

    private function fetch(?callable $log): array
    {
        $log && $log('Fetching Bybit instruments index (spot + linear) ...');
        $index = ['spot' => [], 'linear' => []];
        foreach (['spot', 'linear'] as $category) {
            $cursor = '';
            while (true) {
                $params = ['category' => $category, 'limit' => 1000];
                if ($cursor !== '') {
                    $params['cursor'] = $cursor;
                }
                $res = $this->http->get('/v5/market/instruments-info', $params);
                foreach (($res['list'] ?? []) as $inst) {
                    $index[$category][$inst['symbol']] = $inst;
                }
                $cursor = $res['nextPageCursor'] ?? '';
                if ($cursor === '') {
                    break;
                }
                $this->http->throttle();
            }
            $log && $log("   $category: " . count($index[$category]) . ' instruments');
            if ($index[$category] === []) {
                // Never replace a good cache with an empty one — every ticker would stop resolving.
                throw new \RuntimeException("Bybit returned no {$category} instruments");
            }
        }

        @mkdir(dirname($this->cachePath), 0775, recursive: true);
        file_put_contents($this->cachePath, JsonFormatter::encode2Space($index));
        $this->index = $index;
        $this->loaded = true;
        return $index;
    }

    /** @return array{0:string,1:string,2:array<string,mixed>}|null  [bybitSymbol, category, instrument] */
    public function resolveSpotOrLinear(string $hashhedgeSymbol, array $overrides): ?array
    {
        $this->ensureLoaded();
        if (array_key_exists($hashhedgeSymbol, $overrides)) {
            $forced = $overrides[$hashhedgeSymbol];
            if ($forced === null) {
                return null;
            }
            foreach (['spot', 'linear'] as $cat) {
                if (isset($this->index[$cat][$forced])) {
                    return [$forced, $cat, $this->index[$cat][$forced]];
                }
            }
            return null;
        }
        foreach (["{$hashhedgeSymbol}USDT", "{$hashhedgeSymbol}PERP", "{$hashhedgeSymbol}USDC"] as $candidate) {
            foreach (['spot', 'linear'] as $cat) {
                if (isset($this->index[$cat][$candidate])) {
                    return [$candidate, $cat, $this->index[$cat][$candidate]];
                }
            }
        }
        return null;
    }

    public function resolveLinearOnly(string $hashhedgeSymbol, array $overrides): ?string
    {
        $this->ensureLoaded();
        $candidates = [];
        if (array_key_exists($hashhedgeSymbol, $overrides) && $overrides[$hashhedgeSymbol] !== null) {
            $candidates[] = $overrides[$hashhedgeSymbol];
        }
        $candidates = array_merge($candidates, ["{$hashhedgeSymbol}USDT", "{$hashhedgeSymbol}PERP", "{$hashhedgeSymbol}USDC"]);
        foreach ($candidates as $cand) {
            if (isset($this->index['linear'][$cand])) {
                return $cand;
            }
        }
        return null;
    }

    /** @return array<string, array<string, mixed>> */
    public function linear(): array
    {
        $this->ensureLoaded();
        return $this->index['linear'];
    }
}
