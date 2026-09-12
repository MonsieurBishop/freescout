<?php
/**
 * Standalone, DB-free, vendor-free harness for FetchEmails::separateReply() and the
 * two IT #1175 helpers, isInterleavedReply() and isAttributionOnly().
 *
 * Same reason as _gmail_dwd_standalone_harness.php: composer.lock pins Laravel 5.5 /
 * PHP 7.x, so the project's PHPUnit cannot run on a box with a modern PHP. This loads
 * the REAL FetchEmails, App\Misc\Mail, App\Misc\Helper and Html2Text classes and shims
 * only the framework pieces separateReply() touches: Illuminate\Console\Command (the
 * parent class), \Eventy, \Str, \MailHelper, \Helper and config().
 *
 * Run from the repo root (needs the dom and mbstring extensions):
 *   php tests/Unit/_separate_reply_standalone_harness.php
 * Point it at another FetchEmails.php to see the behaviour before a patch:
 *   php tests/Unit/_separate_reply_standalone_harness.php /tmp/FetchEmails.orig.php
 * Exit code 0 when every check passes, 1 otherwise.
 */

namespace Illuminate\Console {
    if (!class_exists(Command::class)) {
        class Command
        {
            public function __construct() {}
        }
    }
}

namespace {
    error_reporting(E_ALL & ~E_DEPRECATED);

    $root = dirname(__DIR__, 2);
    $target = $argv[1] ?? $root.'/app/Console/Commands/FetchEmails.php';

    if (!function_exists('config')) {
        function config($key, $default = null)
        {
            return $default;
        }
    }
    class Eventy
    {
        public static function filter($hook, $value)
        {
            return $value;
        }
    }
    class Str
    {
        public static function startsWith($haystack, $needle)
        {
            return strncmp((string) $haystack, (string) $needle, strlen((string) $needle)) === 0;
        }
    }

    require $root.'/vendor/symfony/polyfill-mbstring/Mbstring.php';
    require $root.'/overrides/html2text/html2text/src/Html2Text.php';
    require $root.'/app/Misc/Helper.php';
    require $root.'/app/Misc/Mail.php';
    class_alias('App\Misc\Helper', 'Helper');
    class_alias('App\Misc\Mail', 'MailHelper');
    require $target;

    $cmd = (new ReflectionClass('App\Console\Commands\FetchEmails'))->newInstanceWithoutConstructor();
    $cmd->mailbox = (object) ['before_reply' => ''];

    // Customer reply, as processMessage() calls it for a message from a customer.
    $separate = function ($html) use ($cmd) {
        return $cmd->separateReply($html, true, true, false, '');
    };
    $text = function ($html) {
        return \Helper::htmlToText($html);
    };

    $wrap = function ($body) {
        return '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body>'.$body.'</body></html>';
    };
    $prefix = '<div class="moz-cite-prefix">On 8/3/2026 4:34 AM, Newschoolers AM wrote:<br></div>';
    // Our outbound message as Thunderbird quotes it: the fsReplyAbove marker sits inside.
    $quote = function ($inner) {
        return '<blockquote type="cite" cite="mid:abc@advally.com"><div class="fsReplyAbove"></div>'.$inner.'</blockquote>';
    };

    $cases = [
        // The IT #1175 shape: Ken Payne on conv 81439, answers typed between the quoted questions.
        'thunderbird inline, answers between the quoted questions' => [
            $wrap($prefix
                .$quote('<p>1. How much disk headroom is left?</p>')
                .'<p>ANSWER-ONE 212 GB free on /var</p>'
                .'<blockquote type="cite"><p>2. Was a backup taken?</p></blockquote>'
                .'<p>ANSWER-TWO yes, 03:10 UTC</p>'),
            ['ANSWER-ONE', 'ANSWER-TWO'], [],
        ],
        'thunderbird inline, greeting above then answers between quotes' => [
            $wrap('<p>Hi, answers inline.</p>'.$prefix
                .$quote('<p>1. Disk?</p>').'<p>ANSWER-ONE 212 GB</p>'),
            ['Hi, answers inline.', 'ANSWER-ONE'], [],
        ],
        'thunderbird bottom-post, reply below the whole quote' => [
            $wrap($prefix.$quote('<p>Old question</p>').'<p>BOTTOM-REPLY here</p>'),
            ['BOTTOM-REPLY'], [],
        ],
        'apple mail inline' => [
            $wrap('<div>On Aug 3, 2026, at 04:34, Newschoolers AM &lt;newschoolers@advally.com&gt; wrote:</div>'
                .'<blockquote type="cite"><div>Question one?</div></blockquote><div>APPLE-ANSWER</div>'),
            ['APPLE-ANSWER'], [],
        ],
        // Behaviour that must NOT change.
        'thunderbird top-post still drops the quote' => [
            $wrap('<p>TOP-REPLY thanks, done.</p>'.$prefix.$quote('<p>OLD-QUOTED text</p>')),
            ['TOP-REPLY'], ['OLD-QUOTED'],
        ],
        'thunderbird top-post with signature below the quote still drops the quote' => [
            $wrap('<p>TOP-REPLY thanks.</p>'.$prefix.$quote('<p>OLD-QUOTED text</p>')
                .'<pre class="moz-signature" cols="72">-- '."\n".'Ken Payne</pre>'),
            ['TOP-REPLY'], ['OLD-QUOTED'],
        ],
        'gmail top-post still drops the quote' => [
            $wrap('<div dir="ltr">GMAIL-REPLY sounds good</div><br><div class="gmail_quote"><div dir="ltr" class="gmail_attr">On Mon, Aug 3, 2026 at 4:34 AM Newschoolers AM wrote:<br></div>'
                .'<blockquote class="gmail_quote" style="margin:0px"><div>OLD-QUOTED text</div></blockquote></div>'),
            ['GMAIL-REPLY'], ['OLD-QUOTED'],
        ],
    ];

    $fail = 0;
    $check = function ($ok, $label) use (&$fail) {
        echo ($ok ? 'PASS ' : 'FAIL ').$label."\n";
        if (!$ok) {
            $fail++;
        }
    };

    echo 'target: '.$target."\n";
    foreach ($cases as $name => [$html, $must, $mustNot]) {
        $out = $text($separate($html));
        $ok = true;
        foreach ($must as $needle) {
            $ok = $ok && strpos($out, $needle) !== false;
        }
        foreach ($mustNot as $needle) {
            $ok = $ok && strpos($out, $needle) === false;
        }
        $check($ok, $name.' -> '.json_encode(trim(preg_replace('/\s+/', ' ', $out))));
    }

    if (method_exists($cmd, 'isAttributionOnly')) {
        $F = 'App\Console\Commands\FetchEmails';
        $check($F::isAttributionOnly('On 8/3/2026 4:34 AM, Newschoolers AM wrote:'), 'attribution: english');
        $check($F::isAttributionOnly("On Mon, Aug 3, 2026 at 4:34 AM\nNewschoolers AM <a@b.c> wrote:"), 'attribution: wrapped over two lines');
        $check($F::isAttributionOnly('Le 3 août 2026 à 04:34, Newschoolers AM a écrit :'), 'attribution: french');
        $check(!$F::isAttributionOnly('Thanks, on it. The backup was taken at 03:10.'), 'attribution: a real reply is not one');
        $check(!$F::isAttributionOnly("Done.\nOn 8/3/2026 4:34 AM, Newschoolers AM wrote:"), 'attribution: a reply above the line is not one');
        $check(!$F::isInterleavedReply('<p>plain reply, no quote</p>'), 'interleaved: no cite quote');
    } else {
        echo "SKIP helper checks: target has no isAttributionOnly()\n";
    }

    echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
    exit($fail ? 1 : 0);
}
