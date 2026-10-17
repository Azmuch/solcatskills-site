<?php
/*
 * Booked nights for solcatskills.com, merged from the Airbnb and Vrbo calendar exports.
 *
 * The private export links live in a file outside the web root (never in the public repo). Airbnb's feed carries
 * reservation codes and guests' phone digits, so this endpoint publishes nothing but date ranges.
 * Feeds are re-read at most every 30 minutes; if a platform is unreachable, its last good dates are kept
 * (never shows a night as free just because a feed failed).
 */
declare(strict_types=1);

const TTL = 1800;      // refresh every 30 minutes
const RETRY = 600;     // after a failed read, try again in 10 minutes
const HORIZON = 550;   // days ahead to publish

function outside(string $name): string
{
    $p = dirname(__DIR__, 2) . '/' . $name;   // the folder above public_html
    return is_writable(dirname($p)) ? $p : sys_get_temp_dir() . '/' . $name;
}

function feeds(): array
{
    $j = @file_get_contents(dirname(__DIR__, 2) . '/sol-ical.json');
    $d = $j === false ? null : json_decode($j, true);
    return is_array($d) ? $d : [];
}

function fetch_ics(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 15, CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0 (compatible; solcatskills.com availability)', 'Accept: text/calendar'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return (is_string($body) && $code === 200 && strpos($body, 'BEGIN:VCALENDAR') !== false) ? $body : null;
}

/** [start, end) date pairs (end = checkout morning); everything else in the feed is dropped */
function parse_ranges(string $ics): array
{
    $ics = str_replace(["\r\n ", "\r\n\t", "\n ", "\n\t"], '', $ics);
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $m);
    $out = [];
    foreach ($m[1] as $ev) {
        if (!preg_match('/^DTSTART[^:\r\n]*:(\d{8})/m', $ev, $s)) {
            continue;
        }
        $start = DateTime::createFromFormat('!Ymd', $s[1]);
        $end = preg_match('/^DTEND[^:\r\n]*:(\d{8})/m', $ev, $e) ? DateTime::createFromFormat('!Ymd', $e[1]) : (clone $start)->modify('+1 day');
        if ($start && $end && $end > $start) {
            $out[] = [$start->format('Y-m-d'), $end->format('Y-m-d')];
        }
    }
    return $out;
}

function merge(array $ranges): array
{
    usort($ranges, fn ($a, $b) => strcmp($a[0], $b[0]));
    $today = date('Y-m-d');
    $limit = date('Y-m-d', strtotime('+' . HORIZON . ' days'));
    $out = [];
    foreach ($ranges as [$a, $b]) {
        if ($b <= $today || $a > $limit) {
            continue;
        }
        if ($out && $a <= $out[count($out) - 1][1]) {
            if ($b > $out[count($out) - 1][1]) {
                $out[count($out) - 1][1] = $b;
            }
        } else {
            $out[] = [$a, $b];
        }
    }
    return $out;
}

function read_cache(string $p): array
{
    $j = @file_get_contents($p);
    $d = $j === false ? null : json_decode($j, true);
    return is_array($d) ? $d : [];
}

function refresh(string $p, array $c): array
{
    $lock = @fopen($p . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return $c;
    }
    $now = time();
    $ok = false;
    foreach (feeds() as $name => $url) {
        if (!is_string($url) || $url === '') {
            continue;
        }
        $ics = fetch_ics($url);
        if ($ics !== null) {
            $c['src'][$name] = ['ranges' => parse_ranges($ics), 'at' => $now];
            $ok = true;
        }
    }
    $c['checked'] = $now;
    $c['failed'] = !$ok;
    $tmp = $p . '.' . getmypid();
    if (@file_put_contents($tmp, json_encode($c)) !== false) {
        @rename($tmp, $p);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return $c;
}

function send(array $c): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex');
    if (empty($c['src'])) {
        http_response_code(503);
        header('Cache-Control: no-store');
        echo '{}';
        return;
    }
    $all = [];
    $updated = 0;
    foreach ($c['src'] as $s) {
        $all = array_merge($all, $s['ranges']);
        $updated = max($updated, (int) $s['at']);
    }
    header('Cache-Control: public, max-age=300');
    echo json_encode(['booked' => merge($all), 'updated' => gmdate('c', $updated)]);
}

$p = outside('sol-availability-cache.json');
$c = read_cache($p);
$age = time() - (int) ($c['checked'] ?? 0);
$stale = $age >= (empty($c['src']) ? 60 : (!empty($c['failed']) ? RETRY : TTL));   // nothing yet: retry after a minute

if (!$stale) {
    send($c);
} elseif (!empty($c['src'])) {
    send($c);   // answer now with what we have, refresh for the next visitor
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
