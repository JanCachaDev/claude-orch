<?php
declare(strict_types=1);
/**
 * Keeps the shared "Model and effort / Team size" block identical in every skill.
 *
 *   php -n tools/sync-levels.php          rewrite plugins/orch/skills/<name>/SKILL.md blocks from tools/levels.md
 *   php -n tools/sync-levels.php --check  change nothing; exit 1 when any block differs from tools/levels.md
 *
 * A skill opts in by containing the lines `<!-- orch:levels -->` and `<!-- /orch:levels -->`;
 * everything between them is replaced. Skills without the markers are left alone.
 */
$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);
$src = @file_get_contents("$root/tools/levels.md");
if ($src === false) {
    fwrite(STDERR, "missing tools/levels.md\n");
    exit(2);
}
$block = rtrim(str_replace("\r\n", "\n", $src), "\n");
$open = '<!-- orch:levels -->';
$close = '<!-- /orch:levels -->';
$files = glob("$root/plugins/orch/skills/*/SKILL.md") ?: [];
sort($files);
$drift = [];
$synced = 0;
foreach ($files as $file) {
    $raw = (string)file_get_contents($file);
    $crlf = str_contains($raw, "\r\n");
    $text = str_replace("\r\n", "\n", $raw);
    $rel = substr($file, strlen($root) + 1);
    $a = substr_count($text, $open);
    $b = substr_count($text, $close);
    if ($a === 0 && $b === 0) {
        continue;
    }
    if ($a !== 1 || $b !== 1 || strpos($text, $open) > strpos($text, $close)) {
        fwrite(STDERR, "$rel: broken or duplicated orch:levels markers\n");
        exit(2);
    }
    $new = preg_replace_callback(
        '/' . preg_quote($open, '/') . '\n(?:.*?\n)?' . preg_quote($close, '/') . '/s',
        fn() => "$open\n$block\n$close",
        $text,
        1
    );
    if ($new === null) {
        fwrite(STDERR, "$rel: regex failed\n");
        exit(2);
    }
    $synced++;
    if ($new === $text) {
        continue;
    }
    $drift[] = $rel;
    if (!$check) {
        file_put_contents($file, $crlf ? str_replace("\n", "\r\n", $new) : $new);
    }
}
if ($check) {
    if ($drift) {
        fwrite(STDERR, "levels block out of sync (run: php -n tools/sync-levels.php):\n  " . implode("\n  ", $drift) . "\n");
        exit(1);
    }
    echo "levels in sync ($synced skills)\n";
    exit(0);
}
echo $drift ? 'updated: ' . implode(', ', $drift) . "\n" : "levels already in sync ($synced skills)\n";
