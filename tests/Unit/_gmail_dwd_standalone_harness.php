<?php
/**
 * Standalone, DB-free, vendor-free harness for the Gmail DWD logic in
 * App\Misc\Mail. This exists ONLY because this machine cannot install the
 * project's PHPUnit (composer.lock pins Laravel 5.5 / PHP 7.x; local PHP is 8.5).
 *
 * It loads the REAL App\Misc\Mail class by shimming the few global helpers the
 * two target methods touch (config(), now(), \Helper), then exercises:
 *   - gmailDwdEnabledForMailbox()  -> real method, every flag branch
 *   - oauthGetAccessTokenViaServiceAccount() -> real method, error paths only
 *   - the JWT b64url + RS256 assembly -> faithful inline replication, verified
 *     with openssl against a throwaway RSA keypair (the network/curl portion of
 *     the real method cannot run offline, so the deterministic crypto is
 *     replicated 1:1 from the source and proven correct here).
 *
 * The canonical, CI-runnable PHPUnit version is GmailDwdMailTest.php in this dir.
 * Run: php tests/Unit/_gmail_dwd_standalone_harness.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$GLOBALS['__test_config'] = [];
$GLOBALS['__test_log_calls'] = [];

// ---- global shims the target methods reference -----------------------------
if (!function_exists('config')) {
    function config($key, $default = null) {
        return $GLOBALS['__test_config'][$key] ?? $default;
    }
}
if (!function_exists('now')) {
    // Minimal stand-in; only ->toDateTimeString() is used, on the success path
    // which the offline tests never reach.
    function now() {
        return new class {
            public function toDateTimeString() { return '2026-01-01 00:00:00'; }
        };
    }
}

// \Helper is referenced only on the curl path (setCurlDefaultOptions, log).
if (!class_exists('Helper')) {
    class Helper {
        public static function setCurlDefaultOptions($ch) { /* no-op for tests */ }
        public static function log($a, $b, $c = []) { $GLOBALS['__test_log_calls'][] = [$a, $b, $c]; }
    }
}

// Stub the App\* classes the Mail file aliases at the top so the file parses
// and the class definition loads. They're only *referenced* (not instantiated)
// by the two target methods, so empty stubs are enough to load the class.
foreach (['Mailbox', 'Option', 'SendLog'] as $cls) {
    $fqcn = 'App\\' . $cls;
    if (!class_exists($fqcn)) {
        eval("namespace App; class {$cls} {}");
    }
}
// ActivityLog constants are referenced on the (untested-here) error-log path.
if (!class_exists('App\\ActivityLog')) {
    eval('namespace App; class ActivityLog {
        const NAME_EMAILS_FETCHING = "emails_fetching";
        const NAME_EMAILS_SENDING = "emails_sending";
        const DESCRIPTION_EMAILS_FETCHING_ERROR = "fetch_error";
        const DESCRIPTION_EMAILS_SENDING_ERROR_TO_CUSTOMER = "send_error";
    }');
}

require __DIR__ . '/../../app/Misc/Mail.php';

use App\Misc\Mail;

// ---- tiny assert harness ----------------------------------------------------
$passed = 0; $failed = 0; $fails = [];
function check($cond, $label) {
    global $passed, $failed, $fails;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; $fails[] = $label; echo "  FAIL  $label\n"; }
}
function set_config($arr) { $GLOBALS['__test_config'] = $arr; }

// A fake mailbox: configurable provider + id, mimics oauthGetParam('provider').
function fake_mailbox($provider, $id) {
    return new class($provider, $id) {
        public $id; private $provider;
        public function __construct($provider, $id) { $this->provider = $provider; $this->id = $id; }
        public function oauthGetParam($k) { return $k === 'provider' ? $this->provider : null; }
    };
}

// ---- throwaway RSA keypair for the JWT signing test -------------------------
$rsa = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);
if ($rsa === false) {
    fwrite(STDERR, "openssl_pkey_new failed: " . openssl_error_string() . "\n");
    exit(2);
}
openssl_pkey_export($rsa, $priv_pem);
$pub_pem = openssl_pkey_get_details($rsa)['key'];

// Write the private key to a temp JSON key file (the shape the minter expects).
$key_file = tempnam(sys_get_temp_dir(), 'dwdkey_');
file_put_contents($key_file, json_encode([
    'client_email' => 'sa@proj.iam.gserviceaccount.com',
    'private_key'  => $priv_pem,
]));

echo "== gmailDwdEnabledForMailbox: provider gate ==\n";
set_config(['app.gmail_dwd_key' => $key_file, 'app.gmail_dwd_mailboxes' => 'all']);
check(Mail::gmailDwdEnabledForMailbox(null) === false, 'null mailbox -> false');
check(Mail::gmailDwdEnabledForMailbox(fake_mailbox('ms', 1)) === false, 'microsoft provider -> false');
check(Mail::gmailDwdEnabledForMailbox(fake_mailbox('gw', 1)) === true, 'google provider + all -> true');

echo "== gmailDwdEnabledForMailbox: key path gate ==\n";
set_config(['app.gmail_dwd_key' => '', 'app.gmail_dwd_mailboxes' => 'all']);
check(Mail::gmailDwdEnabledForMailbox(fake_mailbox('gw', 1)) === false, 'empty key path -> false');
set_config(['app.gmail_dwd_key' => '/no/such/file/xyz.json', 'app.gmail_dwd_mailboxes' => 'all']);
check(Mail::gmailDwdEnabledForMailbox(fake_mailbox('gw', 1)) === false, 'unreadable key path -> false');

echo "== gmailDwdEnabledForMailbox: rollout-set matching ==\n";
set_config(['app.gmail_dwd_key' => $key_file, 'app.gmail_dwd_mailboxes' => '']);
check(Mail::gmailDwdEnabledForMailbox(fake_mailbox('gw', 1)) === false, 'empty set -> false');
set_config(['app.gmail_dwd_key' => $key_file, 'app.gmail_dwd_mailboxes' => '   ']);
check(Mail::gmailDwdEnabledForMailbox(fake_mailbox('gw', 1)) === false, 'whitespace-only set -> false (trim)');
set_config(['app.gmail_dwd_key' => $key_file, 'app.gmail_dwd_mailboxes' => 'all']);
check(Mail::gmailDwdEnabledForMailbox(fake_mailbox('gw', 7)) === true, '"all" -> any id true');
set_config(['app.gmail_dwd_key' => $key_file, 'app.gmail_dwd_mailboxes' => '3,7,11']);
check(Mail::gmailDwdEnabledForMailbox(fake_mailbox('gw', 7)) === true, 'CSV contains id 7 -> true');
check(Mail::gmailDwdEnabledForMailbox(fake_mailbox('gw', 8)) === false, 'CSV missing id 8 -> false');
set_config(['app.gmail_dwd_key' => $key_file, 'app.gmail_dwd_mailboxes' => ' 3 , 7 , 11 ']);
check(Mail::gmailDwdEnabledForMailbox(fake_mailbox('gw', 7)) === true, 'CSV with whitespace around ids -> id 7 true');
// int-vs-string: method casts $mailbox->id to (string) and compares strictly.
set_config(['app.gmail_dwd_key' => $key_file, 'app.gmail_dwd_mailboxes' => '7']);
check(Mail::gmailDwdEnabledForMailbox(fake_mailbox('gw', 7)) === true, 'int id 7 matches string "7" (strict cast)');
check(Mail::gmailDwdEnabledForMailbox(fake_mailbox('gw', '7')) === true, 'string id "7" matches "7"');
set_config(['app.gmail_dwd_key' => $key_file, 'app.gmail_dwd_mailboxes' => '70']);
check(Mail::gmailDwdEnabledForMailbox(fake_mailbox('gw', 7)) === false, 'id 7 does NOT match "70" (no substring/loose match)');

echo "== oauthGetAccessTokenViaServiceAccount: error paths (no network) ==\n";
set_config(['app.gmail_dwd_key' => '']);
$r = Mail::oauthGetAccessTokenViaServiceAccount('user@x.com');
check(isset($r['error']) && !isset($r['a_token']), 'empty key path -> error, no token');
check(strpos($r['error'], 'not configured or not readable') !== false, 'empty key path -> correct error string');

set_config(['app.gmail_dwd_key' => '/no/such/file.json']);
$r = Mail::oauthGetAccessTokenViaServiceAccount('user@x.com');
check(isset($r['error']) && !isset($r['a_token']), 'unreadable key path -> error, no token');

// malformed key (valid JSON, missing client_email/private_key)
$bad = tempnam(sys_get_temp_dir(), 'badkey_');
file_put_contents($bad, json_encode(['foo' => 'bar']));
set_config(['app.gmail_dwd_key' => $bad]);
$r = Mail::oauthGetAccessTokenViaServiceAccount('user@x.com');
check(isset($r['error']) && strpos($r['error'], 'malformed') !== false, 'malformed key -> "malformed" error, no token');
check(!isset($r['a_token']), 'malformed key -> no token');

// not-JSON-at-all content
file_put_contents($bad, 'this is not json {{{');
set_config(['app.gmail_dwd_key' => $bad]);
$r = Mail::oauthGetAccessTokenViaServiceAccount('user@x.com');
check(isset($r['error']) && strpos($r['error'], 'malformed') !== false, 'non-JSON key -> "malformed" error');

// well-formed key but empty subject
set_config(['app.gmail_dwd_key' => $key_file]);
$r = Mail::oauthGetAccessTokenViaServiceAccount('');
check(isset($r['error']) && strpos($r['error'], 'subject') !== false, 'empty subject -> "subject ... missing" error');
check(!isset($r['a_token']), 'empty subject -> no token');

echo "== JWT b64url + RS256 assembly (faithful replication, openssl-verified) ==\n";
// This block replicates the EXACT deterministic JWT assembly from
// oauthGetAccessTokenViaServiceAccount() (source lines ~1010-1031). The real
// method runs an unconditional curl to oauth2.googleapis.com after signing,
// which cannot execute offline, so the crypto is mirrored here and proven.
$b64url = function ($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
};
$client_email = 'sa@proj.iam.gserviceaccount.com';
$subject_email = 'mailbox@advally.com';
$now = time();
$jwt_header = $b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
$jwt_claim  = $b64url(json_encode([
    'iss'   => $client_email,
    'sub'   => $subject_email,
    'scope' => 'https://mail.google.com/',
    'aud'   => 'https://oauth2.googleapis.com/token',
    'iat'   => $now,
    'exp'   => $now + 3600,
]));
$signing_input = $jwt_header . '.' . $jwt_claim;
$signature = '';
$ok = openssl_sign($signing_input, $signature, $priv_pem, OPENSSL_ALGO_SHA256);
$jwt = $signing_input . '.' . $b64url($signature);

check($ok === true, 'openssl_sign with throwaway RSA key succeeds');

$parts = explode('.', $jwt);
check(count($parts) === 3, 'JWT has exactly 3 dot-separated segments');

// b64url decode helper (inverse of $b64url)
$b64url_decode = function ($s) {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) { $s .= str_repeat('=', 4 - $pad); }
    return base64_decode($s);
};

$header = json_decode($b64url_decode($parts[0]), true);
check(is_array($header) && $header['alg'] === 'RS256' && $header['typ'] === 'JWT',
    'header decodes to {alg:RS256, typ:JWT}');

$claims = json_decode($b64url_decode($parts[1]), true);
foreach (['iss', 'sub', 'scope', 'aud', 'iat', 'exp'] as $field) {
    check(array_key_exists($field, $claims), "claims contain '$field'");
}
check($claims['iss'] === $client_email, 'claim iss == client_email');
check($claims['sub'] === $subject_email, 'claim sub == subject_email (impersonation)');
check($claims['scope'] === 'https://mail.google.com/', 'claim scope == mail.google.com');
check($claims['aud'] === 'https://oauth2.googleapis.com/token', 'claim aud == token endpoint');
check($claims['exp'] - $claims['iat'] === 3600, 'claim exp == iat + 3600 (1h)');

// b64url segments must contain no +, /, or = padding
check(strpos($parts[0] . $parts[1] . $parts[2], '+') === false
    && strpos($parts[0] . $parts[1] . $parts[2], '/') === false
    && strpos($parts[0] . $parts[1] . $parts[2], '=') === false,
    'all segments are URL-safe base64 (no +, /, =)');

// signature verifies against the throwaway PUBLIC key
$sig_raw = $b64url_decode($parts[2]);
$verify = openssl_verify($signing_input, $sig_raw, $pub_pem, OPENSSL_ALGO_SHA256);
check($verify === 1, 'signature verifies against throwaway public key (openssl_verify == 1)');

// tampered input must NOT verify
$verify_bad = openssl_verify($signing_input . 'x', $sig_raw, $pub_pem, OPENSSL_ALGO_SHA256);
check($verify_bad !== 1, 'tampered signing input does NOT verify');

@unlink($key_file);
@unlink($bad);

echo "\n=================================\n";
echo "PASSED: $passed   FAILED: $failed\n";
if ($failed) { echo "FAILURES:\n - " . implode("\n - ", $fails) . "\n"; exit(1); }
echo "ALL GREEN\n";
exit(0);
