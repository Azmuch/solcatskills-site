<?php
/* One-time: stores the private Airbnb/Vrbo calendar export links outside the web root (sol-ical.json),
   so they never sit in the public repo. Only accepts a POST carrying the setup token (only its hash is here).
   Removed from the site right after use. */
declare(strict_types=1);
const TOKEN_SHA256 = '5a2a6538ca5c8e8f736c40dd84aea1c219109d0a769ba3cf672daad057109db9';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(TOKEN_SHA256, hash('sha256', (string) ($_POST['token'] ?? '')))) {
    http_response_code(404);
    exit;
}
$a = (string) ($_POST['airbnb'] ?? '');
$v = (string) ($_POST['vrbo'] ?? '');
if (!preg_match('#^https://www\.airbnb\.com/calendar/ical/\d+\.ics\?t=[0-9a-f]+$#', $a) || !preg_match('#^https?://www\.vrbo\.com/icalendar/[0-9a-f]+\.ics$#', $v)) {
    http_response_code(400);
    echo 'bad links';
    exit;
}
$p = dirname(__DIR__, 2) . '/sol-ical.json';
if (@file_put_contents($p, json_encode(['airbnb' => $a, 'vrbo' => $v]), LOCK_EX) === false) {
    http_response_code(500);
    echo 'not writable';
    exit;
}
@chmod($p, 0600);
echo 'stored';
