<?php
declare(strict_types=1);
/**
 * Shows the orch version loaded in this session, the installed one and the newest published one,
 * plus what to do when they differ.
 *
 *   php -n version.php [--offline] [--json]
 */
require __DIR__ . '/lib-version.php';

$opts = getopt('', ['offline', 'json', 'session:']);
orch_lang_init(orch_project_dir(), (string)($opts['session'] ?? ''));
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
    $advice[] = orch_t("Je venku novější verze $latest. Aktualizuj: claude plugin marketplace update claude-orch && claude plugin update orch@claude-orch — pak v běžících sezeních /reload-plugins.",
        "A newer version $latest is out. Update: claude plugin marketplace update claude-orch && claude plugin update orch@claude-orch — then run /reload-plugins in running sessions.");
} elseif (orch_reload_needed($loaded, $installed)) {
    $advice[] = orch_t("Toto sezení běží na $loaded, nainstalovaná je $installed: spusť /reload-plugins (nebo sezení zavři a otevři znovu).",
        "This session is running $loaded, $installed is installed: run /reload-plugins (or close and reopen the session).");
}
if (!$advice) {
    $advice[] = orch_t('Vše aktuální.', 'Everything is up to date.');
}

if (isset($opts['json'])) {
    echo json_encode(['loaded' => $loaded, 'installed' => $installed, 'marketplace' => $market, 'remote' => $remote,
        'latest' => $latest, 'reload_needed' => orch_reload_needed($loaded, $installed), 'advice' => $advice],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$show = fn(?string $v) => $v ?? '?';
echo orch_t("orch verze\n", "orch version\n");
printf(orch_t("  toto sezení:    %s\n", "  this session:   %s\n"), $show($loaded));
printf(orch_t("  nainstalováno:  %s\n", "  installed:      %s\n"), $show($installed));
printf(orch_t("  nejnovější:     %s%s\n", "  latest:         %s%s\n"), $show($latest), $remote !== null ? ' (GitHub)'
    : (isset($opts['offline']) ? orch_t(' (offline, z marketplace)', ' (offline, from the marketplace)') : orch_t(' (GitHub nedostupný, z marketplace)', ' (GitHub unreachable, from the marketplace)')));
foreach ($advice as $a) {
    echo "→ $a\n";
}
