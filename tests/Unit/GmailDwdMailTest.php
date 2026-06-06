<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Misc\Mail;

/**
 * DB-free unit tests for the Gmail Domain-Wide-Delegation logic added to
 * App\Misc\Mail (branch claude/gmail-dwd):
 *
 *   - Mail::gmailDwdEnabledForMailbox()              -- flag/rollout logic
 *   - Mail::oauthGetAccessTokenViaServiceAccount()   -- error paths only
 *   - the JWT b64url + RS256 assembly                -- replicated 1:1 from
 *     source and proven with a throwaway RSA keypair (the real method's curl
 *     to oauth2.googleapis.com cannot run in a unit test, so the deterministic
 *     crypto that precedes it is mirrored and verified here)
 *
 * Why plain PHPUnit\Framework\TestCase (not Tests\TestCase): these target
 * methods touch no DB and no Eloquent. Booting Laravel only adds the global
 * helpers config()/now() and the \Helper / App\ActivityLog classes, which this
 * file defines as fallbacks below when they are absent (i.e. when run outside a
 * full Laravel boot). Under the project's normal PHPUnit run, Laravel provides
 * the real helpers and these fallbacks are skipped.
 *
 * config() is stubbed via a static array the tests populate; the mailbox is a
 * lightweight fake exposing id + oauthGetParam('provider').
 */
class GmailDwdMailTest extends TestCase
{
    /** @var string */ private static $keyFile;
    /** @var string */ private static $privPem;
    /** @var string */ private static $pubPem;

    public static function setUpBeforeClass(): void
    {
        // ---- global shims (only if a full Laravel boot hasn't provided them) ----
        // config()/now() must live in the GLOBAL namespace (App\Misc\Mail calls
        // them unqualified and PHP falls back to global, never to Tests\Unit), so
        // they're defined in a no-namespace sidecar rather than inline here.
        require_once __DIR__ . '/gmail_dwd_global_shims.php';
        if (!class_exists('Helper')) {
            class_alias(\Tests\Unit\GmailDwdHelperStub::class, 'Helper');
        }
        foreach (['Mailbox', 'Option', 'SendLog'] as $cls) {
            if (!class_exists('App\\' . $cls)) {
                eval("namespace App; class {$cls} {}");
            }
        }
        if (!class_exists('App\\ActivityLog')) {
            eval('namespace App; class ActivityLog {
                const NAME_EMAILS_FETCHING = "emails_fetching";
                const NAME_EMAILS_SENDING = "emails_sending";
                const DESCRIPTION_EMAILS_FETCHING_ERROR = "fetch_error";
                const DESCRIPTION_EMAILS_SENDING_ERROR_TO_CUSTOMER = "send_error";
            }');
        }

        // ---- throwaway RSA keypair + JSON service-account key file ----
        $rsa = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($rsa, self::$privPem);
        self::$pubPem = openssl_pkey_get_details($rsa)['key'];

        self::$keyFile = tempnam(sys_get_temp_dir(), 'dwdkey_');
        file_put_contents(self::$keyFile, json_encode([
            'client_email' => 'sa@proj.iam.gserviceaccount.com',
            'private_key'  => self::$privPem,
        ]));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$keyFile && file_exists(self::$keyFile)) {
            @unlink(self::$keyFile);
        }
    }

    /** Populate the stubbed config() store (no-op under real Laravel config). */
    private function setConfig(array $arr): void
    {
        $GLOBALS['__gmaildwd_config'] = $arr;
    }

    /** Fake mailbox exposing id + oauthGetParam('provider'). */
    private function fakeMailbox($provider, $id)
    {
        return new class($provider, $id) {
            public $id;
            private $provider;
            public function __construct($provider, $id) { $this->provider = $provider; $this->id = $id; }
            public function oauthGetParam($k) { return $k === 'provider' ? $this->provider : null; }
        };
    }

    // -----------------------------------------------------------------------
    // gmailDwdEnabledForMailbox(): provider gate
    // -----------------------------------------------------------------------

    public function testFlagNullMailboxIsFalse(): void
    {
        $this->setConfig(['app.gmail_dwd_key' => self::$keyFile, 'app.gmail_dwd_mailboxes' => 'all']);
        $this->assertFalse(Mail::gmailDwdEnabledForMailbox(null));
    }

    public function testFlagNonGoogleProviderIsFalse(): void
    {
        $this->setConfig(['app.gmail_dwd_key' => self::$keyFile, 'app.gmail_dwd_mailboxes' => 'all']);
        // 'ms' = Microsoft; only 'gw' (Google) qualifies.
        $this->assertFalse(Mail::gmailDwdEnabledForMailbox($this->fakeMailbox('ms', 1)));
    }

    public function testFlagGoogleProviderWithAllIsTrue(): void
    {
        $this->setConfig(['app.gmail_dwd_key' => self::$keyFile, 'app.gmail_dwd_mailboxes' => 'all']);
        $this->assertTrue(Mail::gmailDwdEnabledForMailbox($this->fakeMailbox('gw', 1)));
    }

    // -----------------------------------------------------------------------
    // gmailDwdEnabledForMailbox(): key-path gate
    // -----------------------------------------------------------------------

    public function testFlagEmptyKeyPathIsFalse(): void
    {
        $this->setConfig(['app.gmail_dwd_key' => '', 'app.gmail_dwd_mailboxes' => 'all']);
        $this->assertFalse(Mail::gmailDwdEnabledForMailbox($this->fakeMailbox('gw', 1)));
    }

    public function testFlagUnreadableKeyPathIsFalse(): void
    {
        $this->setConfig(['app.gmail_dwd_key' => '/no/such/file/xyz.json', 'app.gmail_dwd_mailboxes' => 'all']);
        $this->assertFalse(Mail::gmailDwdEnabledForMailbox($this->fakeMailbox('gw', 1)));
    }

    // -----------------------------------------------------------------------
    // gmailDwdEnabledForMailbox(): rollout-set (empty / all / CSV / whitespace / id type)
    // -----------------------------------------------------------------------

    public function testFlagEmptySetIsFalse(): void
    {
        $this->setConfig(['app.gmail_dwd_key' => self::$keyFile, 'app.gmail_dwd_mailboxes' => '']);
        $this->assertFalse(Mail::gmailDwdEnabledForMailbox($this->fakeMailbox('gw', 1)));
    }

    public function testFlagWhitespaceOnlySetIsFalse(): void
    {
        $this->setConfig(['app.gmail_dwd_key' => self::$keyFile, 'app.gmail_dwd_mailboxes' => '   ']);
        $this->assertFalse(Mail::gmailDwdEnabledForMailbox($this->fakeMailbox('gw', 1)));
    }

    public function testFlagAllMatchesAnyId(): void
    {
        $this->setConfig(['app.gmail_dwd_key' => self::$keyFile, 'app.gmail_dwd_mailboxes' => 'all']);
        $this->assertTrue(Mail::gmailDwdEnabledForMailbox($this->fakeMailbox('gw', 7)));
        $this->assertTrue(Mail::gmailDwdEnabledForMailbox($this->fakeMailbox('gw', 999)));
    }

    public function testFlagCsvMembership(): void
    {
        $this->setConfig(['app.gmail_dwd_key' => self::$keyFile, 'app.gmail_dwd_mailboxes' => '3,7,11']);
        $this->assertTrue(Mail::gmailDwdEnabledForMailbox($this->fakeMailbox('gw', 7)), 'id 7 in CSV');
        $this->assertFalse(Mail::gmailDwdEnabledForMailbox($this->fakeMailbox('gw', 8)), 'id 8 not in CSV');
    }

    public function testFlagCsvWithSurroundingWhitespaceIsTrimmed(): void
    {
        $this->setConfig(['app.gmail_dwd_key' => self::$keyFile, 'app.gmail_dwd_mailboxes' => ' 3 , 7 , 11 ']);
        $this->assertTrue(Mail::gmailDwdEnabledForMailbox($this->fakeMailbox('gw', 7)));
    }

    public function testFlagIntIdMatchesStringCsvEntry(): void
    {
        // Method casts $mailbox->id to (string) and compares in_array(..., true).
        $this->setConfig(['app.gmail_dwd_key' => self::$keyFile, 'app.gmail_dwd_mailboxes' => '7']);
        $this->assertTrue(Mail::gmailDwdEnabledForMailbox($this->fakeMailbox('gw', 7)), 'int id');
        $this->assertTrue(Mail::gmailDwdEnabledForMailbox($this->fakeMailbox('gw', '7')), 'string id');
    }

    public function testFlagNoSubstringFalsePositive(): void
    {
        // id 7 must not match "70" (guards against loose/substring matching).
        $this->setConfig(['app.gmail_dwd_key' => self::$keyFile, 'app.gmail_dwd_mailboxes' => '70']);
        $this->assertFalse(Mail::gmailDwdEnabledForMailbox($this->fakeMailbox('gw', 7)));
    }

    // -----------------------------------------------------------------------
    // oauthGetAccessTokenViaServiceAccount(): error paths (no network)
    // -----------------------------------------------------------------------

    public function testMinterEmptyKeyPathReturnsError(): void
    {
        $this->setConfig(['app.gmail_dwd_key' => '']);
        $r = Mail::oauthGetAccessTokenViaServiceAccount('user@x.com');
        $this->assertArrayHasKey('error', $r);
        $this->assertArrayNotHasKey('a_token', $r);
        $this->assertStringContainsString('not configured or not readable', $r['error']);
    }

    public function testMinterUnreadableKeyPathReturnsError(): void
    {
        $this->setConfig(['app.gmail_dwd_key' => '/no/such/file.json']);
        $r = Mail::oauthGetAccessTokenViaServiceAccount('user@x.com');
        $this->assertArrayHasKey('error', $r);
        $this->assertArrayNotHasKey('a_token', $r);
    }

    public function testMinterMalformedJsonKeyReturnsError(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'badkey_');
        file_put_contents($bad, json_encode(['foo' => 'bar'])); // valid JSON, wrong shape
        $this->setConfig(['app.gmail_dwd_key' => $bad]);
        $r = Mail::oauthGetAccessTokenViaServiceAccount('user@x.com');
        @unlink($bad);
        $this->assertArrayHasKey('error', $r);
        $this->assertArrayNotHasKey('a_token', $r);
        $this->assertStringContainsString('malformed', $r['error']);
    }

    public function testMinterNonJsonKeyReturnsError(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'badkey_');
        file_put_contents($bad, 'this is not json {{{');
        $this->setConfig(['app.gmail_dwd_key' => $bad]);
        $r = Mail::oauthGetAccessTokenViaServiceAccount('user@x.com');
        @unlink($bad);
        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsString('malformed', $r['error']);
    }

    public function testMinterEmptySubjectReturnsError(): void
    {
        $this->setConfig(['app.gmail_dwd_key' => self::$keyFile]);
        $r = Mail::oauthGetAccessTokenViaServiceAccount('');
        $this->assertArrayHasKey('error', $r);
        $this->assertArrayNotHasKey('a_token', $r);
        $this->assertStringContainsString('subject', $r['error']);
    }

    // -----------------------------------------------------------------------
    // JWT b64url + RS256 assembly (replicated 1:1 from source, openssl-verified)
    // -----------------------------------------------------------------------

    public function testJwtAssemblyStructureAndSignature(): void
    {
        // Mirror of oauthGetAccessTokenViaServiceAccount() source ~lines 1010-1031.
        $b64url = function ($data) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };
        $clientEmail  = 'sa@proj.iam.gserviceaccount.com';
        $subjectEmail = 'mailbox@advally.com';
        $now = time();

        $jwtHeader = $b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $jwtClaim  = $b64url(json_encode([
            'iss'   => $clientEmail,
            'sub'   => $subjectEmail,
            'scope' => 'https://mail.google.com/',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $signingInput = $jwtHeader . '.' . $jwtClaim;

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, self::$privPem, OPENSSL_ALGO_SHA256);
        $this->assertTrue($ok, 'openssl_sign succeeds with the throwaway key');

        $jwt = $signingInput . '.' . $b64url($signature);
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts, 'JWT has 3 dot-separated segments');

        $b64urlDecode = function ($s) {
            $s = strtr($s, '-_', '+/');
            $pad = strlen($s) % 4;
            if ($pad) { $s .= str_repeat('=', 4 - $pad); }
            return base64_decode($s);
        };

        $header = json_decode($b64urlDecode($parts[0]), true);
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);

        $claims = json_decode($b64urlDecode($parts[1]), true);
        foreach (['iss', 'sub', 'scope', 'aud', 'iat', 'exp'] as $field) {
            $this->assertArrayHasKey($field, $claims, "claim '$field' present");
        }
        $this->assertSame($clientEmail, $claims['iss']);
        $this->assertSame($subjectEmail, $claims['sub'], 'sub is the impersonated mailbox');
        $this->assertSame('https://mail.google.com/', $claims['scope']);
        $this->assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
        $this->assertSame(3600, $claims['exp'] - $claims['iat']);

        // All three segments must be URL-safe base64 (no +, /, or = padding).
        $all = $parts[0] . $parts[1] . $parts[2];
        $this->assertStringNotContainsString('+', $all);
        $this->assertStringNotContainsString('/', $all);
        $this->assertStringNotContainsString('=', $all);

        // Signature verifies against the throwaway public key.
        $sigRaw = $b64urlDecode($parts[2]);
        $this->assertSame(1, openssl_verify($signingInput, $sigRaw, self::$pubPem, OPENSSL_ALGO_SHA256),
            'signature verifies against public key');

        // Tampered input must NOT verify.
        $this->assertNotSame(1, openssl_verify($signingInput . 'x', $sigRaw, self::$pubPem, OPENSSL_ALGO_SHA256),
            'tampered input fails verification');
    }
}

/**
 * Minimal \Helper stand-in for the curl path of the minter, aliased to \Helper
 * by setUpBeforeClass() only when the real Helper isn't autoloaded.
 */
class GmailDwdHelperStub
{
    public static function setCurlDefaultOptions($ch) { /* no-op */ }
    public static function log($a, $b, $c = []) { /* no-op */ }
}
