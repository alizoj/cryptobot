<?php

declare(strict_types=1);

namespace common\services\Trading\Fetcher;

/**
 * Cached Bybit V5 spot + linear instruments index. Used by both the price
 * fetcher (to discover the correct trading pair for a HashHedge ticker)
 * and the derivatives fetcher (to resolve the linear perp for funding/OI).
 *
 * Extracted from fetch_bybit_data.php::fetch_instruments_index() and
 * resolve_bybit_pair() / resolve_linear_pair().
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
     * Load (or refetch) the index. Cached to disk so subsequent runs are free.
     */
    public function ensureLoaded(bool $forceRefresh = false, ?callable $log = null): array
    {
        if (!$forceRefresh && is_file($this->cachePath)) {
            $this->index = json_decode((string)file_get_contents($this->cachePath), true, flags: JSON_THROW_ON_ERROR);
            $this->loaded = true;
            return $this->index;
        }
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
