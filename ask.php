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
Codoo is an AI guest assistant for short-stay rental operators. It handles the guest conversation from booking through check-in, the stay and checkout — automatically, in the guest's own language. It's a product by KittyKat (Kitty Kat Technologies).

WHO IT'S FOR
Operators and managers of short-stay / serviced apartments who answer the same guest questions over and over (Wi-Fi, check-in, parking, checkout), across many bookings and languages.

TWO CHANNELS AT ONCE (important — do not say Codoo is WhatsApp-only)
Codoo works in the guest's WhatsApp AND inside the Booking.com chat itself, at the same time. In the Booking.com conversation it reads what the guest writes, answers there, and escalates from there — through the operator's channel manager, no separate login. This matters because most guests never move to WhatsApp: treating Booking.com as read-only would mean ignoring the majority. When a guest's WhatsApp window is closed (WhatsApp only allows free-form replies for 24 hours after the guest writes), Codoo delivers the check-in instructions through the Booking.com chat instead, so nobody arrives at a locked door because a channel was unavailable. Both sides merge into one conversation per guest for the team, with every line labelled: the guest, Codoo, or the person from the team who stepped in.

HOW IT WORKS
It sits on top of the operator's existing channel manager (e.g. Beds24) and their WhatsApp Business number — no new app for guests, they use what they already have. It works with bookings from Booking.com, Airbnb and direct. Setup: connect the channel manager and the WhatsApp number, fill each apartment in once, then Codoo answers per booking automatically. An apartment is served only after it is explicitly switched on — a new listing never starts talking to guests by itself.

THE GUEST JOURNEY (it is more than an autoresponder)
On booking it greets the guest and asks the one question that decides everything else: are you arriving by car or on foot. Before arrival it sends the way in as a photo walkthrough of that specific apartment — the building, the entrance, the keypad, the key safe — and a car guest gets the garage route instead of the pedestrian one, never both. During the stay it answers instantly. Before departure it asks the planned check-out time, which is what cleaning actually needs. After checkout it asks for a review — and never asks a guest whose stay went wrong.

WHAT IT DOES WHEN SOMETHING FAILS (this is half the product)
Messages get refused, connections drop, a send stops half-way. Codoo records what actually reached the guest rather than what it tried to send, and re-sends what did not; if a reply never got produced, it comes back and answers. Before an arrival it checks the apartment's own data and warns the team about gaps — a missing photo, an empty car route — while there is still time to fix them. The team sees all of it: what was sent, what was read, what is still missing.

THE TEAM PANEL
A web panel for the operator's team: every conversation across both channels, arrivals with delivery status, an apartment screen where the data and photos live (this is where onboarding actually happens), guests still waiting on a reply, a training page where the team's own answers become facts Codoo reuses, and a plain-language health page. No new app for the team either — it is a website behind a password.

A HUMAN IS ALWAYS ABOVE IT
A real problem — a breakdown, a lockout, a complaint, anything about money — goes to the operator on Telegram, and to their phone on WhatsApp if Telegram goes unread. Codoo then goes silent for that guest and does not resume until a person releases it. It never talks over a human.

FEATURES
Any-language replies (50+, detects the guest's language), self check-in (lockbox codes, key locations, the way in), Wi-Fi and parking, human handoff to the operator on Telegram, pre-sales (answers people who haven't booked, suggests apartments, sends booking links, passes date and price questions to the operator), conversation memory, and a post-checkout review nudge.

BUILT IN-HOUSE (key point)
Codoo is built end to end by KittyKat. It is not a layer on top of a third-party chatbot tool. Direct integrations: the official WhatsApp Cloud API, the operator's channel manager and the AI model — no reseller platform in the middle, no per-seat markup, no vendor lock-in. Guest data stays with the operator. Tone, languages, rules and escalation are all tunable. Codoo answers only from the operator's real apartment data; if a detail isn't set it asks the operator instead of guessing.

LIVE PROOF
Codoo is running today in a 20-apartment operation in Tallinn — on its own WhatsApp line and inside the Booking.com chat, 24/7, replying in any language the guest writes in.

WHAT SETUP REALLY COSTS (be honest, it earns trust)
Deployment takes minutes; two things do not. Getting a WhatsApp business number approved by Meta takes weeks, so it should be started early. And collecting each apartment's entry photos — every step, per apartment — is the real work, which is why the panel is built so the operator's own team can do it themselves.

PRICING
Pricing is tailored to the operator's size and needs. Don't invent numbers. For a quote, invite them to contact the team on WhatsApp (+372 5704 7525) or email hello@kittykat.tech.

NEXT STEPS
You are the live demo — they are talking to Codoo right now. For a setup tailored to their apartments, invite them to message the team on WhatsApp (+372 5704 7525) or email hello@kittykat.tech.

BOUNDARIES
Answer about Codoo, short-stay hosting and how Codoo could help the visitor. If asked something unrelated, gently steer back. If you don't know, say so and offer to connect them with the team. Never invent features, integrations, customers or prices. Never use markdown.
PROMPT;

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://codoo.kittykat.tech', 'https://www.codoo.kittykat.tech'];
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
if ($apiKey === '') { http_response_code(500); echo json_encode(['reply' => "The assistant isn't fully set up yet. Please email us at hello@kittykat.tech."]); exit; }

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
    echo json_encode(['reply' => "Sorry, I couldn't reach the assistant just now. You can always reach us on WhatsApp at +372 5704 7525 or email hello@kittykat.tech."]);
    exit;
}
$data = json_decode((string)$resp, true);
$reply = $data['content'][0]['text'] ?? '';
echo json_encode(['reply' => $reply !== '' ? $reply : "Sorry, I didn't catch that — could you rephrase?"]);
