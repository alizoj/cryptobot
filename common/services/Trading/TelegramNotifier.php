<?php

declare(strict_types=1);

namespace common\services\Trading;

use Yii;

/**
 * Minimal Telegram Bot-API client — sendMessage + sendPhoto.
 *
 * Credentials come from Yii::$app->params['telegram'] (see params.php).
 * If credentials are blank, send() is a no-op that returns false so
 * commands can still run dry during local development. The old signal
 * message formatters were removed with the signal engine; the new
 * strategy will define its own message formats.
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

}
