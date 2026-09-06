<?php
namespace Meelano\Crypto;

use Meelano\Ai\CurlTransport;
use Meelano\Ai\Transport;
use Throwable;

/**
 * لایهٔ دادهٔ بازار کریپتو — منابع عمومی (Binance + CoinGecko).
 *
 * اصل مهندسی: هر عدد با «منبع» برچسب می‌خورد و در خطا به منبع جایگزین یا
 * دادهٔ کش سقوط کنترل‌شده می‌کند؛ هرگز دادهٔ جعلی به‌عنوان واقعی جا زده نمی‌شود.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class MarketData
{
    /** @var Transport */
    private $transport;
    /** @var array */
    private $cfg;

    public function __construct(?Transport $transport = null, array $cfg = [])
    {
        $this->transport = $transport ?: new CurlTransport();
        $this->cfg = $cfg;
    }

    /** فهرست ارزهای فعال با метrik های ۲۴ ساعته (برای رصد کل بازار). */
    public function tickers(int $limit = 100): array
    {
        // Binance 24hr ticker برای جفت‌های USDT
        $res = $this->get('https://api.binance.com/api/v3/ticker/24hr');
        if ($res['status'] === 200 && is_array($res['body_parsed'])) {
            $out = [];
            foreach ($res['body_parsed'] as $row) {
                $symbol = (string)($row['symbol'] ?? '');
                if (substr($symbol, -4) !== 'USDT') {
                    continue;
                }
                $out[] = [
                    'symbol' => $symbol,
                    'base' => substr($symbol, 0, -4),
                    'last' => (float)($row['lastPrice'] ?? 0),
                    'change_pct' => (float)($row['priceChangePercent'] ?? 0),
                    'quote_volume' => (float)($row['quoteVolume'] ?? 0),
                    'high' => (float)($row['highPrice'] ?? 0),
                    'low' => (float)($row['lowPrice'] ?? 0),
                    'source' => 'binance',
                ];
                if (count($out) >= $limit * 3) {
                    break;
                }
            }
            // مرتب‌سازی بر اساس حجم نزولی و گرفتن top
            usort($out, static function ($a, $b) {
                return $b['quote_volume'] <=> $a['quote_volume'];
            });
            return array_slice($out, 0, $limit);
        }

        // سقوط به CoinGecko
        $cg = $this->get('https://api.coingecko.com/api/v3/coins/markets?vs_currency=usd&order=volume_desc&per_page=' . $limit . '&page=1&price_change_percentage=24h');
        if ($cg['status'] === 200 && is_array($cg['body_parsed'])) {
            $out = [];
            foreach ($cg['body_parsed'] as $row) {
                $out[] = [
                    'symbol' => strtoupper((string)($row['symbol'] ?? '')) . 'USDT',
                    'base' => strtoupper((string)($row['symbol'] ?? '')),
                    'last' => (float)($row['current_price'] ?? 0),
                    'change_pct' => (float)($row['price_change_percentage_24h'] ?? 0),
                    'quote_volume' => (float)($row['total_volume'] ?? 0),
                    'high' => (float)($row['high_24h'] ?? 0),
                    'low' => (float)($row['low_24h'] ?? 0),
                    'source' => 'coingecko',
                ];
            }
            return $out;
        }
        return [];
    }

    /**
     * کندل‌های یک جفت‌ارز.
     *
     * @return array{ok:bool,candles:array<array{time:int,open:float,high:float,low:float,close:float,volume:float}>,source:string,error:?string}
     */
    public function candles(string $symbol, string $interval = '1h', int $limit = 200): array
    {
        $url = 'https://api.binance.com/api/v3/klines?symbol=' . urlencode($symbol)
            . '&interval=' . urlencode($interval) . '&limit=' . $limit;
        $res = $this->get($url);
        if ($res['status'] === 200 && is_array($res['body_parsed'])) {
            $candles = [];
            foreach ($res['body_parsed'] as $k) {
                if (!is_array($k) || count($k) < 6) {
                    continue;
                }
                $candles[] = [
                    'time' => (int)($k[0] / 1000),
                    'open' => (float)$k[1],
                    'high' => (float)$k[2],
                    'low' => (float)$k[3],
                    'close' => (float)$k[4],
                    'volume' => (float)$k[5],
                ];
            }
            if ($candles) {
                return ['ok' => true, 'candles' => $candles, 'source' => 'binance', 'error' => null];
            }
        }
        return ['ok' => false, 'candles' => [], 'source' => 'none', 'error' => $res['error'] ?? 'داده کندل دریافت نشد'];
    }

    private function get(string $url): array
    {
        try {
            $res = $this->transport->request('GET', $url, [
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'MeelanoCrypto/2.0'],
                'timeout' => (int)($this->cfg['timeout'] ?? 15),
            ]);
            return [
                'status' => $res['status'],
                'body_parsed' => json_decode($res['body'], true),
                'error' => $res['error'] !== '' ? $res['error'] : null,
            ];
        } catch (Throwable $e) {
            return ['status' => 0, 'body_parsed' => null, 'error' => $e->getMessage()];
        }
    }
}
