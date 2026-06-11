<?php
// On-demand deploy: pull latest from GitHub into this folder (the web root is a
// git clone of the site repo's default branch). Password-gated via DEPLOY_KEY.
$DEPLOY_KEY = getenv('DEPLOY_KEY') ?: (file_exists(__DIR__.'/.env') ? trim(array_reduce(
  file(__DIR__.'/.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES),
  fn($c,$l)=>str_starts_with($l,'DEPLOY_KEY=')?substr($l,11):$c,'')) : '');
if (empty($DEPLOY_KEY)) { http_response_code(500); die('DEPLOY_KEY not configured'); }
if (!isset($_GET['key']) || !hash_equals($DEPLOY_KEY, $_GET['key'])) { http_response_code(403); die('Access denied'); }
header('Content-Type: text/plain');

$mode = $_GET['mode'] ?? 'hard';
if (!in_array($mode, ['soft','hard','status'], true)) die("Unknown mode: $mode");
$dir = __DIR__; chdir($dir);
$branch = trim((string)shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null')) ?: 'main';
echo "=== $mode deploy ===\nDir: $dir\nBranch: $branch\n\n";

if ($mode === 'status') {
  echo shell_exec('git status 2>&1')."\n".shell_exec('git log --oneline -5 2>&1')."\n";
} elseif ($mode === 'soft') {
  echo shell_exec('git stash 2>&1')."\n".shell_exec("git pull origin ".escapeshellarg($branch)." 2>&1")."\n";
} else { // hard
  echo shell_exec('git fetch origin 2>&1')."\n";
  echo shell_exec("git reset --hard origin/".escapeshellarg($branch)." 2>&1")."\n";
  echo shell_exec("git pull origin ".escapeshellarg($branch)." 2>&1")."\n";
}

if ($mode !== 'status') {
  echo "\n--- Post-deploy ---\n";
  foreach (['index.html'] as $f)
    echo file_exists("$dir/$f") ? "$f: OK\n" : "$f: MISSING\n";
  $payload = [
    'commit'=>trim((string)shell_exec('git rev-parse HEAD 2>/dev/null')),
    'short' =>trim((string)shell_exec('git rev-parse --short HEAD 2>/dev/null')),
    'branch'=>$branch,
    'message'=>trim((string)shell_exec('git log -1 --pretty=%s 2>/dev/null')),
    'deployedAt'=>date('c'),
  ];
  file_put_contents("$dir/version.json", json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  echo "version.json: written ({$payload['short']})\n";
  if (function_exists('opcache_reset')) { opcache_reset(); echo "Opcache: cleared\n"; }
}
echo "\nDone ".date('Y-m-d H:i:s')."\n";
