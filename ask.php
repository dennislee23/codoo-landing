<?php
/**
 * Codoo — on-page "Ask Codoo" assistant backend.
 * POST JSON { message: string, history?: [{role:"user"|"assistant", content:string}] }
 *   -> { reply: string }
 * Reads ANTHROPIC_API_KEY from .env in this folder (gitignored, .htaccess-blocked).
 * Per-IP rate limit, length caps. Answers only about the Codoo product.
 */
declare(strict_types=1);

const MODEL          = 'claude-sonnet-4-5';
const MAX_TOKENS     = 500;
const MAX_MSG_LEN    = 1000;
const MAX_HISTORY    = 8;          // turns kept
const RATE_LIMIT     = 12;         // requests
const RATE_WINDOW    = 60;         // per seconds
const API_URL        = 'https://api.anthropic.com/v1/messages';

const SYSTEM_PROMPT = <<<'PROMPT'
You are the assistant on Codoo's product website. You answer questions from prospective customers — short-stay apartment operators, property managers and partners — about the Codoo product. Be helpful, concise and factual. Plain text only: no markdown, no asterisks, no headings. Keep answers to 2–5 sentences unless asked for more. Reply in the visitor's language.

WHAT CODOO IS
Codoo is an AI guest assistant for short-stay rental operators. It runs on WhatsApp and handles the guest conversation from the moment of booking through check-in, the stay and checkout — automatically, in the guest's own language. It's a product by KittyKat (Kitty Kat Technologies).

WHO IT'S FOR
Operators and managers of short-stay / serviced apartments who answer the same guest questions over and over (Wi-Fi, check-in, parking, checkout), across many bookings and languages.

HOW IT WORKS
It sits on top of the operator's existing channel manager (e.g. Beds24) and their WhatsApp Business number — no new app for guests, they use the WhatsApp they already have. It works with bookings from Booking.com, Airbnb and direct. Booking.com passes the guest's phone directly; Airbnb masks contact, so Airbnb guests reach Codoo by messaging the number (opt-in). Setup: connect the channel manager and WhatsApp number, add each apartment's details (check-in, lockbox, Wi-Fi, parking, rules) once, then Codoo answers per booking automatically.

THE GUEST JOURNEY (it is more than an autoresponder)
On booking it greets the guest and confirms the essentials. Before arrival it sends directions, the lockbox code, parking and how to get in (including arriving by car). During the stay it answers instantly, and a real problem — a breakdown, lockout or complaint — is escalated to the operator on Telegram while Codoo pauses itself. At checkout it sends checkout steps, then asks for a review, only when the stay went well.

FEATURES
Any-language replies (50+, detects the guest's language), self check-in (lockbox codes, key locations, the way in), Wi-Fi and parking, human handoff to the operator on Telegram, pre-sales (answers people who haven't booked, suggests apartments, sends booking links, passes date and price questions to the operator), conversation memory, and a post-checkout review nudge.

BUILT IN-HOUSE (key point)
Codoo is built end to end by KittyKat. It is not a layer on top of a third-party chatbot tool. Direct integrations: the official WhatsApp Cloud API, the operator's channel manager and the AI model — no reseller platform in the middle, no per-seat markup, no vendor lock-in. Guest data stays with the operator. Tone, languages, rules and escalation are all tunable. Codoo answers only from the operator's real apartment data; if a detail isn't set it asks the operator instead of guessing.

LIVE PROOF
Codoo is running today across a 30+ apartment operation in Tallinn — on its own WhatsApp line, 24/7, replying in any language the guest writes in.

PRICING
Pricing is tailored to the operator's size and needs. Don't invent numbers. For a quote, invite them to message on WhatsApp (+372 5382 9955) or email hello@kittykat.tech.

NEXT STEPS
They can try the live assistant on WhatsApp, or contact the team (WhatsApp +372 5382 9955 / hello@kittykat.tech) for a demo tailored to their apartments.

BOUNDARIES
Answer about Codoo, short-stay hosting and how Codoo could help the visitor. If asked something unrelated, gently steer back. If you don't know, say so and offer to connect them with the team. Never invent features, integrations, customers or prices. Never use markdown.
PROMPT;

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://booking.kittykat.tech', 'https://www.booking.kittykat.tech'];
if (in_array($origin, $allowed, true) || preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }

// rate limit (per IP, file-based sliding window)
$ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rf   = sys_get_temp_dir() . '/codoo_rl_' . md5($ip);
$now  = time();
$hits = is_file($rf) ? array_filter(array_map('intval', explode(',', (string)@file_get_contents($rf))), fn($t) => $t > $now - RATE_WINDOW) : [];
if (count($hits) >= RATE_LIMIT) { http_response_code(429); echo json_encode(['reply' => "You're sending messages a little fast — give it a few seconds and try again."]); exit; }
$hits[] = $now; @file_put_contents($rf, implode(',', $hits));

$body = json_decode((string)file_get_contents('php://input'), true);
$message = is_array($body) && isset($body['message']) && is_string($body['message']) ? trim($body['message']) : '';
if ($message === '') { http_response_code(400); echo json_encode(['error' => 'empty']); exit; }
if (mb_strlen($message) > MAX_MSG_LEN) $message = mb_substr($message, 0, MAX_MSG_LEN);

// API key from .env
$apiKey = '';
if (is_file(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        if (str_starts_with($l, 'ANTHROPIC_API_KEY=')) { $apiKey = trim(substr($l, 18)); break; }
    }
}
if ($apiKey === '') { http_response_code(500); echo json_encode(['reply' => "The assistant isn't fully set up yet. Please message us on WhatsApp at +372 5382 9955."]); exit; }

// build messages
$messages = [];
if (is_array($body) && isset($body['history']) && is_array($body['history'])) {
    foreach (array_slice($body['history'], -MAX_HISTORY) as $h) {
        $role = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $content = is_string($h['content'] ?? null) ? mb_substr(trim($h['content']), 0, MAX_MSG_LEN) : '';
        if ($content !== '') $messages[] = ['role' => $role, 'content' => $content];
    }
}
$messages[] = ['role' => 'user', 'content' => $message];

$payload = json_encode([
    'model'      => MODEL,
    'max_tokens' => MAX_TOKENS,
    'system'     => [['type' => 'text', 'text' => SYSTEM_PROMPT, 'cache_control' => ['type' => 'ephemeral']]],
    'messages'   => $messages,
]);

$ch = curl_init(API_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_HTTPHEADER => [
        'content-type: application/json',
        'anthropic-version: 2023-06-01',
        'x-api-key: ' . $apiKey,
    ],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false || $code >= 400) {
    http_response_code(502);
    echo json_encode(['reply' => "Sorry, I couldn't reach the assistant just now. You can always message us on WhatsApp at +372 5382 9955."]);
    exit;
}
$data = json_decode((string)$resp, true);
$reply = $data['content'][0]['text'] ?? '';
echo json_encode(['reply' => $reply !== '' ? $reply : "Sorry, I didn't catch that — could you rephrase?"]);
