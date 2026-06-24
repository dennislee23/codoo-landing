<?php
// Reverse proxy: serves the Codoo team panel (rendered by the codoo-bot Worker)
// under codoo.kittykat.tech. Forwards the team's Basic Auth straight to the Worker,
// which is the auth gate (password = the Worker's WEBHOOK_VERIFY_TOKEN). Paths:
// /panel /inbox /arrivals /learn (+ /learn/act, POST /learn/add) /img/<id>, /arrivals/checkin.
declare(strict_types=1);
$WORKER = 'https://codoo-bot.hello-071.workers.dev';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$qs   = $_SERVER['QUERY_STRING'] ?? '';
$target = $WORKER . $path . ($qs !== '' ? '?' . $qs : '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && isset($_SERVER['PHP_AUTH_USER'])) {
  $auth = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . ($_SERVER['PHP_AUTH_PW'] ?? ''));
}
$hdrs = [];
if ($auth !== '') $hdrs[] = 'Authorization: ' . $auth;
$isPost = $method === 'POST';
if ($isPost) $hdrs[] = 'Content-Type: application/json';

$ch = curl_init($target);
$opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdrs, CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => true];
if ($isPost) { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = file_get_contents('php://input'); }
curl_setopt_array($ch, $opt);
$resp  = curl_exec($ch);
$code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'text/html; charset=utf-8';
curl_close($ch);
$body = $resp === false ? '' : substr($resp, $hsize);

if ($code === 401) header('WWW-Authenticate: Basic realm="Codoo demo", charset="UTF-8"');
http_response_code($code ?: 502);
header('Content-Type: ' . $ctype);
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
echo $body !== '' ? $body : 'Codoo panel temporarily unavailable.';
