<?php

/**
 * Smoke test: Craft CP internals the read-only slideout handling depends on.
 *
 * src/web/assets/dist/discontent.js and discontent.css lean on undocumented
 * Craft.CpScreenSlideout internals to hide the Save button and relabel Cancel
 * as “Close” when admin changes are disallowed. Those internals aren’t a public
 * API, so a Craft CP redesign could change them and silently break the
 * read-only Fields / Entry Types slideout.
 *
 * This asserts each assumption against the *installed* Craft’s compiled cp.js,
 * so a `composer update` that shifts them fails loudly here instead of in a
 * client’s read-only environment. It’s an early-warning smoke test, not a proof
 * of correctness — a failure means “re-check discontent.js/.css against the new
 * Craft build”, not necessarily “it’s broken”.
 *
 * Run:  composer test:slideout        (or: php tests/slideout-assumptions.php)
 *       php tests/slideout-assumptions.php /path/to/craftcms/cms   (explicit CMS)
 * Exits non-zero if any assumption no longer holds.
 */

/**
 * Locate Craft’s compiled cp.js without booting the app: an explicit CLI path,
 * else the nearest `vendor/craftcms/cms` walking up from the CWD, then from this
 * file (covers standalone `composer install` and install-as-a-dependency).
 */
function locate_cp_js(array $argv): ?string
{
    $rel = 'src/web/assets/cp/dist/cp.js';

    if (isset($argv[1])) {
        foreach ([$argv[1], rtrim($argv[1], '/') . '/' . $rel, rtrim($argv[1], '/') . '/cp.js'] as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
    }

    foreach ([getcwd() ?: '', __DIR__] as $start) {
        $dir = $start;
        while ($dir && $dir !== '.') {
            $c = "$dir/vendor/craftcms/cms/$rel";
            if (is_file($c)) {
                return $c;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }

    return null;
}

$jsFile = locate_cp_js($argv);
if ($jsFile === null) {
    fwrite(STDERR, "Could not find Craft's cp.js (vendor/craftcms/cms). Run `composer install`, run this\n");
    fwrite(STDERR, "from inside a Craft project, or pass the craftcms/cms path as an argument.\n");
    exit(2);
}

$js = file_get_contents($jsFile);

/**
 * Each assumption: what Craft must still do, the plugin code that relies on it,
 * and a matcher against the compiled bundle. Kept as literal/loose patterns
 * (string values survive minification even though local vars don’t).
 */
$assumptions = [
    [
        'name' => 'Craft.CpScreenSlideout exists',
        'usedBy' => 'discontent.js — new Craft.CpScreenSlideout(editAction, …)',
        'ok' => str_contains($js, 'CpScreenSlideout'),
    ],
    [
        'name' => 'Slideout defaults to a <form> container (so a Save button is rendered)',
        'usedBy' => 'discontent.js/.css — read-only handling hides that Save button',
        'ok' => (bool)preg_match('/containerElement\s*:\s*[\'"]form[\'"]/', $js),
    ],
    [
        'name' => "Save button carries the 'submit' class",
        'usedBy' => 'discontent.css — .ct-readonly-slideout .so-footer .btn.submit { display:none }',
        'ok' => (bool)preg_match('/addClass\(\s*[\'"]submit[\'"]\s*\)/', $js),
    ],
    [
        'name' => "Slideout footer uses the 'so-footer' class",
        'usedBy' => 'discontent.css — .ct-readonly-slideout .so-footer …',
        'ok' => str_contains($js, 'so-footer'),
    ],
    [
        'name' => 'Slideout exposes $cancelBtn and $container members',
        'usedBy' => "discontent.js — slideout.\$cancelBtn.text('Close'); slideout.\$container.addClass(…)",
        'ok' => str_contains($js, '$cancelBtn') && str_contains($js, '$container'),
    ],
    [
        'name' => 'closeOnEsc / closeOnShadeClick are recognised slideout settings',
        'usedBy' => 'discontent.js — slideoutSettings.closeOnEsc / closeOnShadeClick (read-only dismissal)',
        'ok' => str_contains($js, 'closeOnEsc') && str_contains($js, 'closeOnShadeClick'),
    ],
];

echo "Discontent — CP slideout assumption smoke test\n";
echo "cp.js: $jsFile\n\n";

$failed = 0;
foreach ($assumptions as $a) {
    if ($a['ok']) {
        echo "  \033[32m✓\033[0m {$a['name']}\n";
    } else {
        $failed++;
        echo "  \033[31m✗\033[0m {$a['name']}\n";
        echo "      relied on by: {$a['usedBy']}\n";
    }
}

echo "\n";
if ($failed > 0) {
    echo "\033[31m$failed assumption(s) no longer hold — re-check discontent.js/.css against this Craft build.\033[0m\n";
    exit(1);
}

echo "\033[32mAll " . count($assumptions) . " slideout assumptions hold.\033[0m\n";
exit(0);
