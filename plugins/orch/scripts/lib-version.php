<?php
declare(strict_types=1);
/**
 * orch versions: the one loaded in this session (the scripts' own plugin dir), the one installed
 * (~/.claude/plugins/installed_plugins.json) and the newest published one (marketplace clone / git tags).
 */
require_once __DIR__ . '/lib-usage.php';

const ORCH_PLUGIN_ID = 'orch@claude-orch';
const ORCH_REPO_URL = 'https://github.com/JanCachaDev/claude-orch.git';

/** Repo used for the remote version lookup; the ORCH_REPO_URL env var overrides it (tests, forks). */
function orch_repo_url(): string
{
    $e = getenv('ORCH_REPO_URL');
    return is_string($e) && $e !== '' ? $e : ORCH_REPO_URL;
}

/** "X.Y.Z" part of a version string (drops build/hash suffixes), or null when it is not semver-like. */
function orch_semver(mixed $v): ?string
{
    return is_string($v) && preg_match('/^\d+\.\d+\.\d+/', $v, $m) ? $m[0] : null;
}

function orch_manifest_version(string $pluginRoot): ?string
{
    $m = json_decode((string)@file_get_contents("$pluginRoot/.claude-plugin/plugin.json"), true);
    return is_array($m) ? orch_semver($m['version'] ?? null) : null;
}

/** Version of the scripts that are running right now (= what this session loaded). */
function orch_loaded_version(): ?string
{
    return orch_manifest_version(dirname(__DIR__));
}

/** Installed version per installed_plugins.json (newest entry across scopes), or null. */
function orch_installed_version(string $home): ?string
{
    $d = json_decode((string)@file_get_contents("$home/plugins/installed_plugins.json"), true);
    $best = null;
    foreach ((array)($d['plugins'][ORCH_PLUGIN_ID] ?? []) as $e) {
        $v = is_array($e) ? orch_semver($e['version'] ?? null) : null;
        if ($v !== null && ($best === null || version_compare($v, $best, '>'))) {
            $best = $v;
        }
    }
    return $best;
}

/** Version in the local marketplace clone (what `claude plugin update` would install). */
function orch_marketplace_version(string $home): ?string
{
    return orch_manifest_version("$home/plugins/marketplaces/claude-orch/plugins/orch");
}

/** Newest vX.Y.Z tag on GitHub (network, short timeout), or null when offline. */
function orch_remote_version(int $timeout = 5): ?string
{
    // Output goes to temp files, not pipes: non-blocking pipe reads don't work on Windows, so polling
    // proc_get_status is the only way to enforce the deadline. No credential prompts (terminal or GUI).
    $cmd = ['git', '-c', 'credential.helper=', '-c', "http.lowSpeedTime=$timeout", '-c', 'http.lowSpeedLimit=1',
        'ls-remote', '--tags', '--refs', orch_repo_url()];
    $env = array_merge(getenv(), ['GIT_TERMINAL_PROMPT' => '0', 'GCM_INTERACTIVE' => 'never', 'GIT_ASKPASS' => '', 'SSH_ASKPASS' => '']);
    $tmp = @tempnam(sys_get_temp_dir(), 'orchv');
    if ($tmp === false) {
        return null;
    }
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $proc = @proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $tmp, 'w'], 2 => ['file', $null, 'w']], $pipes, null, $env);
    if (!is_resource($proc)) {
        @unlink($tmp);
        return null;
    }
    $end = microtime(true) + $timeout;
    while (proc_get_status($proc)['running'] && microtime(true) < $end) {
        usleep(50_000);
    }
    $done = !proc_get_status($proc)['running'];
    if (!$done) {
        if (DIRECTORY_SEPARATOR === '\\') { // kill git's child git-remote-https too, not only git.exe
            @exec('taskkill /T /F /PID ' . (int)proc_get_status($proc)['pid'] . ' 2>NUL');
        }
        proc_terminate($proc);
    }
    proc_close($proc);
    $out = $done ? (string)@file_get_contents($tmp) : '';
    @unlink($tmp);
    $best = null;
    if (preg_match_all('~refs/tags/v(\d+\.\d+\.\d+)$~m', $out, $m)) {
        foreach ($m[1] as $v) {
            $best = ($best === null || version_compare($v, $best, '>')) ? $v : $best;
        }
    }
    return $best;
}

/** True when a strictly newer version is installed than the one this session runs. */
function orch_reload_needed(?string $loaded, ?string $installed): bool
{
    return $loaded !== null && $installed !== null && version_compare($installed, $loaded, '>');
}
