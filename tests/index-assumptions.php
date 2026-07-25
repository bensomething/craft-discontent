<?php

/**
 * Smoke test: Craft CP internals the nested instance rows depend on.
 *
 * Nesting instances under a field or entry type leans on undocumented
 * behaviour in Craft’s admin table:
 *
 *   - it honours a `_showDelete` flag on row data, and calls an
 *     `onRowDoubleClicked` setting with that row data.
 *
 * None of that is public API, so this asserts each one against the *installed*
 * Craft’s compiled bundles: a `composer update` that shifts them fails loudly
 * here rather than quietly breaking the Fields / Entry Types indexes. It’s an
 * early-warning smoke test, not a proof of correctness — a failure means
 * “re-check the controllers / templates / discontent.js against the new Craft
 * build”.
 *
 * Run:  composer test:index          (or: php tests/index-assumptions.php)
 *       php tests/index-assumptions.php /path/to/craftcms/cms   (explicit CMS)
 * Exits non-zero if any assumption no longer holds.
 */

/**
 * Locate the installed craftcms/cms: an explicit CLI path, else the nearest
 * `vendor/craftcms/cms` walking up from the CWD, then from this file (covers
 * standalone `composer install` and install-as-a-dependency).
 */
function locate_cms(array $argv): ?string
{
    $marker = 'src/helpers/Cp.php';

    if (isset($argv[1])) {
        $path = rtrim($argv[1], '/');
        if (is_file("$path/$marker")) {
            return $path;
        }
    }

    foreach ([getcwd() ?: '', __DIR__] as $start) {
        $dir = $start;
        while ($dir && $dir !== '.') {
            $c = "$dir/vendor/craftcms/cms";
            if (is_file("$c/$marker")) {
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

$cms = locate_cms($argv);
if ($cms === null) {
    fwrite(STDERR, "Could not find craftcms/cms. Run `composer install`, run this from inside a\n");
    fwrite(STDERR, "Craft project, or pass the craftcms/cms path as an argument.\n");
    exit(2);
}

$files = [
    'cp' => "$cms/src/web/assets/cp/dist/cp.js",
    'adminTable' => "$cms/src/web/assets/admintable/dist/js/app.js",
];

foreach ($files as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing expected Craft file: $path\n");
        exit(2);
    }
}

$cp = file_get_contents($files['cp']);
$adminTable = file_get_contents($files['adminTable']);

/**
 * Each assumption: what Craft must still do, the plugin code that relies on it,
 * and a matcher against the compiled bundle / source. Kept loose — string
 * values survive minification even though local vars don’t.
 */
$assumptions = [
    [
        'name' => 'Admin table rows can suppress their delete button with `_showDelete`',
        'usedBy' => 'FieldsController::actionTableData() — instance rows aren’t deletable',
        'ok' => str_contains($adminTable, '_showDelete'),
    ],
    [
        'name' => '`onRowDoubleClicked` is a Craft.VueAdminTable setting, called with the row data',
        'usedBy' => 'discontent.js — onInstanceDblClick reads rowData.instance',
        'ok' => str_contains($adminTable, 'onRowDoubleClicked'),
    ],
    [
        'name' => 'Craft.CpScreenSlideout exists (instance rows open their owner in one)',
        'usedBy' => 'discontent.js — onInstanceDblClick opens the section / field / entry type that owns the instance',
        'ok' => str_contains($cp, 'CpScreenSlideout'),
    ],
];

echo "Discontent — index assumption smoke test\n";
echo "craftcms/cms: $cms\n\n";

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
    echo "\033[31m$failed assumption(s) no longer hold — re-check the Fields / Entry Types indexes against this Craft build.\033[0m\n";
    exit(1);
}

echo "\033[32mAll " . count($assumptions) . " index assumptions hold.\033[0m\n";
exit(0);
