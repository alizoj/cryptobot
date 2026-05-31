<?php

declare(strict_types=1);

namespace common\services\Trading;

use Yii;

/**
 * Minimal Telegram Bot-API client — only sendMessage is used.
 *
 * Credentials come from Yii::$app->params['telegram'] (see params.php).
 * If credentials are blank, send() is a no-op that returns false so
 * scan/manage commands can still run dry during local development.
 */
final class TelegramNotifier
{
    private string $botToken;
    private string $chatId;
    private string $parseMode;
    private bool $disablePreview;
    private string $apiBase;

    public function __construct(?array $config = null)
    {
        $cfg = $config ?? (Yii::$app->params['telegram'] ?? []);
        $this->botToken       = (string)($cfg['botToken'] ?? '');
        $this->chatId         = (string)($cfg['chatId']   ?? '');
        $this->parseMode      = (string)($cfg['parseMode'] ?? 'HTML');
        $this->disablePreview = (bool)($cfg['disableWebPagePreview'] ?? true);
        $this->apiBase        = (string)($cfg['apiBase'] ?? 'https://api.telegram.org');
    }

    public function isConfigured(): bool
    {
        return $this->botToken !== '' && $this->chatId !== '';
    }

    /**
     * @return array{ok:bool,error:?string,response:?array<string,mixed>}
     */
    public function send(string $text): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'telegram_not_configured', 'response' => null];
        }
        $url  = $this->apiBase . '/bot' . $this->botToken . '/sendMessage';
        $body = [
            'chat_id'                  => $this->chatId,
            'text'                     => $text,
            'parse_mode'               => $this->parseMode,
            'disable_web_page_preview' => $this->disablePreview,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($body),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $respBody = curl_exec($ch);
        $err      = curl_error($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($respBody === false) {
            return ['ok' => false, 'error' => "curl: $err", 'response' => null];
        }
        $resp = json_decode((string)$respBody, true);
        if (!is_array($resp)) {
            return ['ok' => false, 'error' => "non-json (HTTP $code)", 'response' => null];
        }
        if (!($resp['ok'] ?? false)) {
            return ['ok' => false, 'error' => ($resp['description'] ?? "HTTP $code"), 'response' => $resp];
        }
        return ['ok' => true, 'error' => null, 'response' => $resp];
    }

    /**
     * Send a photo by URL (Telegram fetches the image itself). Used for the
     * QuickChart candlestick images produced by ChartRenderer.
     *
     * @return array{ok:bool,error:?string,response:?array<string,mixed>}
     */
    public function sendPhoto(string $photoUrl, ?string $caption = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'telegram_not_configured', 'response' => null];
        }
        $url  = $this->apiBase . '/bot' . $this->botToken . '/sendPhoto';
        $body = [
            'chat_id' => $this->chatId,
            'photo'   => $photoUrl,
        ];
        if ($caption !== null && $caption !== '') {
            // Telegram caps captions at 1024 chars.
            $body['caption']    = mb_substr($caption, 0, 1024);
            $body['parse_mode'] = $this->parseMode;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($body),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $respBody = curl_exec($ch);
        $err      = curl_error($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($respBody === false) {
            return ['ok' => false, 'error' => "curl: $err", 'response' => null];
        }
        $resp = json_decode((string)$respBody, true);
        if (!is_array($resp)) {
            return ['ok' => false, 'error' => "non-json (HTTP $code)", 'response' => null];
        }
        if (!($resp['ok'] ?? false)) {
            return ['ok' => false, 'error' => ($resp['description'] ?? "HTTP $code"), 'response' => $resp];
        }
        return ['ok' => true, 'error' => null, 'response' => $resp];
    }

    /**
     * Pretty entry-signal card. Plain-text-ish HTML (no markdown) — safe for
     * channels where parse mode is HTML.
     *
     * @param array<string, mixed> $signal one element from SignalEngine::scan()
     */
    public static function formatEntrySignal(array $signal): string
    {
        $arrow = $signal['side'] === 'long' ? '\xE2\x86\x91' : '\xE2\x86\x93';
        $arrow = $signal['side'] === 'long' ? "\u{1F4C8} LONG" : "\u{1F4C9} SHORT";

        $entry = $signal['entry_px'] !== null
            ? '$' . self::px($signal['entry_px'])
            : ('market open ($' . self::px($signal['entry_estimate']) . ' est.)');

        $stopDistPct = $signal['size']['risk_per_unit_pct'] * 100;

        $reasons = [];
        foreach ($signal['reasons'] as $k => $v) {
            $reasons[] = self::compactKv($k, $v);
        }
        $ctxBits = [];
        foreach ($signal['context'] as $k => $v) {
            if ($v === null) {
                continue;
            }
            $ctxBits[] = self::compactKv($k, $v);
        }
        $aggro = $signal['aggressive'] ? '  [AGGRESSIVE]' : '';

        return sprintf(
            "<b>%s %s — %s</b>%s\n"
            . "<b>%s</b>  setup=<b>%s</b>  side=<b>%s</b>\n"
            . "entry: %s\n"
            . "stop:  $%s  (%.2f%% away)\n"
            . "size:  %s units = $%s notional  (risk $%s, beta %s, %s%% of equity)\n"
            . "exit:  T+%d daily close  •  window: %dh\n"
            . "trig:  %s\n"
            . "ctx:   %s",
            $signal['as_of'],
            $signal['symbol'],
            $signal['setup'],
            $aggro,
            $arrow,
            $signal['setup'],
            strtoupper($signal['side']),
            self::esc($entry),
            self::px($signal['stop_px']),
            $stopDistPct,
            self::num($signal['size']['size_units']),
            self::num($signal['size']['notional_usd']),
            self::num($signal['size']['risk_usd']),
            self::num($signal['size']['beta_used']),
            number_format($signal['size']['risk_pct_used'] * 100, 2),
            $signal['time_exit_days'],
            $signal['window_h'],
            self::esc(implode(', ', $reasons)),
            self::esc(implode(', ', $ctxBits)),
        );
    }

    /**
     * Exit signal (funding flip / T+20 close / trail update).
     *
     * @param array<string, mixed> $event
     */
    public static function formatExitSignal(array $event): string
    {
        $title = match ($event['kind']) {
            'time_exit'        => "\u{23F0} T+{$event['days_held']} CLOSE",
            'funding_invalid'  => "\u{26A0}\u{FE0F} FUNDING FLIP — EXIT NOW",
            'trail_update'     => "\u{1F504} STOP TRAIL UPDATE",
            'stop_hit'         => "\u{274C} STOP HIT",
            'manual_close'     => "\u{1F464} MANUAL CLOSE",
            default            => "\u{2139}\u{FE0F} POSITION UPDATE",
        };
        $lines = [
            sprintf("<b>%s</b>  %s %s (%s)", $title, $event['symbol'], $event['setup'], strtoupper($event['side'])),
            sprintf("entry:  $%s on %s", self::px($event['entry_px']), $event['entry_day']),
        ];
        if (isset($event['new_stop'])) {
            $lines[] = sprintf("new stop: $%s  (was $%s)", self::px($event['new_stop']), self::px($event['old_stop']));
        }
        if (isset($event['exit_px'])) {
            $lines[] = sprintf("exit ref: $%s", self::px($event['exit_px']));
        }
        if (isset($event['pnl_pct'])) {
            $sign = $event['pnl_pct'] >= 0 ? '+' : '';
            $lines[] = sprintf("est. P&amp;L: %s%.2f%%", $sign, $event['pnl_pct'] * 100);
        }
        if (isset($event['note'])) {
            $lines[] = self::esc($event['note']);
        }
        return implode("\n", $lines);
    }

    public static function formatScanSummary(string $asOf, int $signalCount, array $diag): string
    {
        return sprintf(
            "<b>Daily scan %s</b>\n%d signals\nscanned: %d/%d   cluster_dumps_yday: %d%s   exit: T+%d",
            $asOf,
            $signalCount,
            $diag['symbols_scanned'] ?? 0,
            $diag['symbols_total']   ?? 0,
            $diag['cluster_dumps_yday'] ?? 0,
            ($diag['cluster_pause_active'] ?? false) ? ' (PAUSED non-L1)' : '',
            $diag['time_exit_days'] ?? 20,
        );
    }

    private static function px(?float $v): string
    {
        if ($v === null) {
            return '-';
        }
        if ($v >= 100)      return number_format($v, 2);
        if ($v >= 1)        return number_format($v, 4);
        if ($v >= 0.01)     return number_format($v, 5);
        return rtrim(rtrim(number_format($v, 8), '0'), '.');
    }

    private static function num(float $v): string
    {
        if (abs($v) >= 1000)  return number_format($v, 0);
        if (abs($v) >= 1)     return number_format($v, 2);
        return number_format($v, 4);
    }

    private static function compactKv(string $k, mixed $v): string
    {
        if (is_float($v)) {
            return $k . '=' . (abs($v) < 0.01 ? sprintf('%.5f', $v) : sprintf('%.3f', $v));
        }
        if (is_int($v) || is_string($v)) {
            return "$k=$v";
        }
        return "$k=?";
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
