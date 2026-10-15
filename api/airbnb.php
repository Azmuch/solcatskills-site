<?php
/*
 * Live Airbnb numbers for solcatskills.com: star rating, review count, Superhost status.
 *
 * Reads the public listing page at most once every 6 hours and keeps the result in a small cache file
 * outside the web root. It never serves a broken value: if Airbnb changes its page or refuses the request,
 * the last good numbers keep being served, and the pages keep their own built-in numbers if there are none.
 */
declare(strict_types=1);

const LISTING = 'https://www.airbnb.com/rooms/46789647';
const TTL = 21600;     // refresh every 6 hours
const RETRY = 3600;    // after a failed read, wait an hour before trying again

function cache_path(): string
{
    $outside = dirname(__DIR__, 2) . '/sol-airbnb-cache.json';   // the folder above public_html: not reachable from the web
    return is_writable(dirname($outside)) ? $outside : sys_get_temp_dir() . '/sol-airbnb-cache.json';
}

function read_cache(string $p): array
{
    $j = @file_get_contents($p);
    $d = $j === false ? null : json_decode($j, true);
    return is_array($d) ? $d : [];
}

function write_cache(string $p, array $d): void
{
    $tmp = $p . '.' . getmypid();
    if (@file_put_contents($tmp, json_encode($d)) !== false) {
        @rename($tmp, $p);
    }
}

function read_listing(): ?array
{
    $ch = curl_init(LISTING);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);
    $html = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($html) || $code !== 200) {
        return null;
    }
    $rating = null;
    foreach (['/"guestSatisfactionOverall"\s*:\s*([0-9]+(?:\.[0-9]+)?)/', '/"starRating"\s*:\s*([0-9]+(?:\.[0-9]+)?)/', '/og:title" content="[^"]*★\s*([0-9]+(?:\.[0-9]+)?)/u'] as $re) {
        if (preg_match($re, $html, $m)) { $rating = (float) $m[1]; break; }
    }
    $count = null;
    foreach (['/"visibleReviewCount"\s*:\s*"?([0-9]+)/', '/"reviewCount"\s*:\s*([0-9]+)/'] as $re) {
        if (preg_match($re, $html, $m)) { $count = (int) $m[1]; break; }
    }
    if ($rating === null || $count === null || $rating < 1 || $rating > 5 || $count < 1 || $count > 100000) {
        return null;
    }
    $out = ['rating' => number_format($rating, 2, '.', ''), 'count' => $count];
    if (preg_match('/"isSuperhost"\s*:\s*(true|false)/', $html, $m)) {
        $out['superhost'] = $m[1] === 'true';
    }
    return $out;
}

function refresh(string $p, array $c): array
{
    $lock = @fopen($p . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return $c;   // another request is already refreshing
    }
    $now = time();
    $got = read_listing();
    // a sudden big drop in reviews means the page was misread, not that reviews vanished
    if ($got && isset($c['count']) && $got['count'] < 0.8 * $c['count']) {
        $got = null;
    }
    $c = $got ? array_merge($c, $got, ['updated' => $now, 'checked' => $now, 'failed' => false])
              : array_merge($c, ['checked' => $now, 'failed' => true]);
    write_cache($p, $c);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $c;
}

function send(array $c): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex');
    if (!isset($c['rating'], $c['count'])) {
        http_response_code(503);
        header('Cache-Control: no-store');
        echo '{}';
        return;
    }
    header('Cache-Control: public, max-age=1800');
    $out = ['rating' => $c['rating'], 'count' => $c['count'], 'updated' => gmdate('c', (int) ($c['updated'] ?? 0))];
    if (isset($c['superhost'])) {
        $out['superhost'] = $c['superhost'];
    }
    echo json_encode($out);
}

$p = cache_path();
$c = read_cache($p);
$age = time() - (int) ($c['checked'] ?? 0);
$stale = $age >= (!empty($c['failed']) ? RETRY : TTL);

if (!$stale) {
    send($c);
} elseif (isset($c['rating'], $c['count'])) {
    // answer with what we have right away, then refresh for the next visitor
    send($c);
    ignore_user_abort(true);
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    refresh($p, $c);
} else {
    send(refresh($p, $c));
}
