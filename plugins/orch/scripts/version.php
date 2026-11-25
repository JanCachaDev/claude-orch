<?php
declare(strict_types=1);
/**
 * Shows the orch version loaded in this session, the installed one and the newest published one,
 * plus what to do when they differ.
 *
 *   php -n version.php [--offline] [--json]
 */
require __DIR__ . '/lib-version.php';

$opts = getopt('', ['offline', 'json']);
$home = orch_claude_home();
$loaded = orch_loaded_version();
$installed = orch_installed_version($home);
$market = orch_marketplace_version($home);
$remote = isset($opts['offline']) ? null : orch_remote_version();
$latest = $remote ?? $market;
foreach ([$market, $installed] as $v) {
    if ($v !== null && ($latest === null || version_compare($v, $latest, '>'))) {
        $latest = $v;
    }
}

$advice = [];
$base = $installed ?? $loaded;
if ($latest !== null && $base !== null && version_compare($latest, $base, '>')) {
    $advice[] = "Je venku novější verze $latest. Aktualizuj: claude plugin marketplace update claude-orch && claude plugin update orch@claude-orch — pak v běžících sezeních /reload-plugins.";
} elseif (orch_reload_needed($loaded, $installed)) {
    $advice[] = "Toto sezení běží na $loaded, nainstalovaná je $installed: spusť /reload-plugins (nebo sezení zavři a otevři znovu).";
}
if (!$advice) {
    $advice[] = 'Vše aktuální.';
}

if (isset($opts['json'])) {
    echo json_encode(['loaded' => $loaded, 'installed' => $installed, 'marketplace' => $market, 'remote' => $remote,
        'latest' => $latest, 'reload_needed' => orch_reload_needed($loaded, $installed), 'advice' => $advice],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$show = fn(?string $v) => $v ?? '?';
echo "orch verze\n";
printf("  toto sezení:    %s\n", $show($loaded));
printf("  nainstalováno:  %s\n", $show($installed));
printf("  nejnovější:     %s%s\n", $show($latest), $remote !== null ? ' (GitHub)' : (isset($opts['offline']) ? ' (offline, z marketplace)' : ' (GitHub nedostupný, z marketplace)'));
foreach ($advice as $a) {
    echo "→ $a\n";
}
