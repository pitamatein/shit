<?php

# report_generator.php

declare(strict_types=1);

date_default_timezone_set('UTC');

$file =
    __DIR__ .
    '/probe_metrics.ndjson';

$outHtml =
    __DIR__ .
    '/report.html';

$serverInfoFile =
    __DIR__ .
    '/server_info.json';


// ============================================================
// LOAD DATA
// ============================================================

if (!file_exists($file)) {
    die("No data file found\n");
}

$lines =
    @file(
        $file,
        FILE_IGNORE_NEW_LINES |
        FILE_SKIP_EMPTY_LINES
    );

$samples = [];
$serverInfo = null;

foreach ($lines ?: [] as $line) {

    $row =
        json_decode(
            $line,
            true
        );

    if (!is_array($row)) {
        continue;
    }

    if (
        ($row['type'] ?? '') ===
        'server_info'
    ) {

        $serverInfo =
            $row;

    } else {

        /*
         * Old probe records did not have a type field.
         * Treat them as performance samples for backwards
         * compatibility.
         */
        $samples[] =
            $row;
    }
}


// ============================================================
// SERVER INFO FALLBACK
// ============================================================

if (
    !$serverInfo &&
    file_exists($serverInfoFile)
) {

    $serverInfo =
        json_decode(
            (string)@file_get_contents(
                $serverInfoFile
            ),
            true
        );

    if (!is_array($serverInfo)) {
        $serverInfo = null;
    }
}


// ============================================================
// EXTERNAL HTTP METRICS
// ============================================================
$externalFile = __DIR__ . '/external_metrics.ndjson';
$externalSamples = [];

if (file_exists($externalFile)) {
    $externalLines = @file(
        $externalFile,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    foreach ($externalLines ?: [] as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }
        $ts = timestampOfExternal($row);
        if ($ts !== null) {
            $row['_ts'] = $ts;
            $externalSamples[] = $row;
        }
    }
}

if (!$samples) {
    die("No valid probe samples found\n");
}


// ============================================================
// UTILITIES
// ============================================================

function col(
    array $data,
    string $key
): array {

    $out = [];

    foreach ($data as $row) {

        if (
            isset($row[$key]) &&
            is_numeric($row[$key])
        ) {
            $out[] =
                (float)$row[$key];
        }
    }

    return $out;
}


function avg(array $values): float
{
    return $values
        ? array_sum($values) /
          count($values)
        : 0.0;
}


function percentile(
    array $values,
    float $percent
): float {

    if (!$values) {
        return 0.0;
    }

    sort(
        $values,
        SORT_NUMERIC
    );

    $index =
        ($percent / 100) *
        (count($values) - 1);

    $lower =
        (int)floor($index);

    $upper =
        (int)ceil($index);

    if ($lower === $upper) {
        return (float)$values[$lower];
    }

    return
        $values[$lower] +
        (
            ($index - $lower) *
            (
                $values[$upper] -
                $values[$lower]
            )
        );
}


function fmtBytes(
    ?int $bytes
): string {

    if (
        $bytes === null ||
        $bytes <= 0
    ) {
        return 'N/A';
    }

    $units = [
        'B',
        'KB',
        'MB',
        'GB',
        'TB',
    ];

    $i = 0;
    $value = (float)$bytes;

    while (
        $value >= 1024 &&
        $i < count($units) - 1
    ) {

        $value /= 1024;
        $i++;
    }

    return
        number_format(
            $value,
            $i >= 2 ? 1 : 0
        ) .
        ' ' .
        $units[$i];
}


function fmtNumber(
    $value,
    int $decimals = 2
): string {

    return is_numeric($value)
        ? number_format(
            (float)$value,
            $decimals
        )
        : 'N/A';
}


function fmtRatio(
    ?float $value
): string {

    return $value === null
        ? 'N/A'
        : number_format(
            $value * 100,
            1
        ) . '%';
}


function bytesToGiB(
    $value
): ?float {

    if (
        $value === null ||
        !is_numeric($value)
    ) {
        return null;
    }

    return
        (float)$value /
        1024 /
        1024 /
        1024;
}


function mariaVar(
    ?array $info,
    string $name
): ?string {

    if (!$info) {
        return null;
    }

    return
        $info['mariadb']['variables'][$name]
        ?? null;
}


function line(
    string $text = ''
): string {
    return $text . "\n";
}


// ============================================================
// THRESHOLDS
// ============================================================

$TH = [

    'fs_sync' =>
        100,

    'inode' =>
        25,

    'php' =>
        500,

    'db' =>
        50,

    'load' =>
        1.0,
];


// ============================================================
// INCIDENT / ANOMALY ENGINE
// ============================================================
/*
 * The incident engine separates isolated observations from sustained
 * episodes and keeps the diagnostic categories distinct:
 *
 *   STORAGE       -> fsync/inode/file/session latency
 *   FS_WRITE      -> buffered filesystem write anomaly
 *   DB_QUERY      -> SQL execution latency
 *   DB_CONN       -> connection-establishment latency
 *   PHP           -> PHP execution latency
 *   CPU           -> elevated load ratio
 *
 * A high buffered write with normal fsync is NOT automatically called
 * a storage incident. Likewise, slow DB connection establishment is
 * not reported as slow SQL execution.
 */

$TH = [
    'fs_write'    => 250.0,
    'fs_sync'     => 100.0,
    'inode'       => 25.0,
    'file_ops'    => 100.0,
    'session'     => 100.0,
    'php'         => 500.0,
    'db_connect'  => 50.0,
    'db_query'    => 50.0,
    'load_ratio'  => 1.0,
];

$EPISODE_GAP_SECONDS = 600;
$MIN_SUSTAINED_SAMPLES = 2;

function timestampOfExternal(array $r): ?int
{
    if (!empty($r['ts']) && is_numeric($r['ts'])) {
        return (int)$r['ts'];
    }

    if (!empty($r['time'])) {
        $ts = strtotime((string)$r['time']);
        return $ts === false ? null : $ts;
    }

    return null;
}

function sampleFlags(array $r, array $th): array
{
    $flags = [];
    $fsWrite = (float)($r['fs_write'] ?? 0);
    $fsSync  = (float)($r['fs_sync'] ?? 0);
    $inode   = (float)($r['inode'] ?? 0);
    $create  = (float)($r['file_create'] ?? 0);
    $delete  = (float)($r['file_delete'] ?? 0);
    $session = (float)($r['session'] ?? 0);
    $php     = (float)($r['php'] ?? 0);
    $dbConn  = (float)($r['db_connect'] ?? 0);
    $dbQuery = (float)($r['db_query'] ?? 0);
    $load    = (float)($r['load_ratio'] ?? 0);

    if ($fsSync > $th['fs_sync'] || $inode > $th['inode'] ||
        $create > $th['file_ops'] || $delete > $th['file_ops'] ||
        $session > $th['session']) {
        $flags['STORAGE'] = true;
    }

    if ($fsWrite > $th['fs_write']) {
        $flags['FS_WRITE'] = true;
    }

    if ($php > $th['php']) {
        $flags['PHP'] = true;
    }

    if ($dbQuery > $th['db_query']) {
        $flags['DB_QUERY'] = true;
    }

    if ($dbConn > $th['db_connect']) {
        $flags['DB_CONN'] = true;
    }

    if ($load > $th['load_ratio']) {
        $flags['CPU'] = true;
    }

    return array_keys($flags);
}

function sampleSeverity(array $r, array $th): float
{
    $ratios = [];
    $checks = [
        'fs_write'    => $th['fs_write'],
        'fs_sync'     => $th['fs_sync'],
        'inode'       => $th['inode'],
        'file_create' => $th['file_ops'],
        'file_delete' => $th['file_ops'],
        'session'     => $th['session'],
        'php'         => $th['php'],
        'db_connect'  => $th['db_connect'],
        'db_query'    => $th['db_query'],
        'load_ratio'  => $th['load_ratio'],
    ];

    foreach ($checks as $key => $limit) {
        if (isset($r[$key]) && is_numeric($r[$key]) && $limit > 0) {
            $value = (float)$r[$key];
            if ($value > $limit) {
                $ratios[] = $value / $limit;
            }
        }
    }

    if (!$ratios) {
        return 0.0;
    }

    // Magnitude contributes up to 60 points. A 1x threshold crossing
    // starts at 15 points; extreme outliers approach 60.
    $maxRatio = max($ratios);
    $magnitude = min(60.0, 15.0 + (log(max(1.0, $maxRatio), 2) * 15.0));
    return $magnitude;
}

function episodeStats(array $rows): array
{
    $metrics = [
        'FS_WRITE'    => 'fs_write',
        'FS_SYNC'     => 'fs_sync',
        'INODE'       => 'inode',
        'FILE_CREATE' => 'file_create',
        'FILE_DELETE' => 'file_delete',
        'SESSION'     => 'session',
        'DB_CONN'     => 'db_connect',
        'DB_QUERY'    => 'db_query',
        'PHP'         => 'php',
        'LOAD_RATIO'  => 'load_ratio',
    ];

    $stats = [];
    foreach ($metrics as $label => $key) {
        $values = [];
        foreach ($rows as $r) {
            if (isset($r[$key]) && is_numeric($r[$key])) {
                $values[] = (float)$r[$key];
            }
        }
        if ($values) {
            $stats[$label] = [
                'avg' => avg($values),
                'p95' => percentile($values, 95),
                'max' => max($values),
            ];
        }
    }
    return $stats;
}

function classifyEpisode(array $e): string
{
    if ($e['count'] < $GLOBALS['MIN_SUSTAINED_SAMPLES']) {
        return 'ISOLATED SPIKE';
    }

    return $e['duration'] >= 600
        ? 'SUSTAINED INCIDENT'
        : 'REPEATED INCIDENT';
}

function correlateExternal(array $episode, array $externalSamples): array
{
    if (!$externalSamples || empty($episode['rows'])) {
        return [];
    }

    $start = timestampOf($episode['rows'][0]);
    $end = timestampOf($episode['rows'][count($episode['rows']) - 1]);
    if ($start === null || $end === null) {
        return [];
    }

    $matched = [];
    foreach ($externalSamples as $row) {
        $ts = $row['_ts'] ?? null;
        if ($ts !== null && $ts >= ($start - 300) && $ts <= ($end + 300)) {
            $matched[] = $row;
        }
    }

    if (!$matched) {
        return [];
    }

    $out = ['count' => count($matched), 'metrics' => []];
    foreach (['dns', 'connect', 'tls', 'ttfb', 'total'] as $key) {
        $values = [];
        foreach ($matched as $row) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                $values[] = (float)$row[$key] * 1000.0;
            }
        }
        if ($values) {
            $out['metrics'][strtoupper($key)] = [
                'avg' => avg($values),
                'p95' => percentile($values, 95),
                'max' => max($values),
            ];
        }
    }
    return $out;
}

function diagnoseEpisode(array $e, array $th): array
{
    $s = $e['stats'];
    $storage = isset($s['FS_SYNC']) && $s['FS_SYNC']['p95'] > $th['fs_sync']
        || isset($s['INODE']) && $s['INODE']['p95'] > $th['inode']
        || isset($s['FILE_CREATE']) && $s['FILE_CREATE']['p95'] > $th['file_ops']
        || isset($s['FILE_DELETE']) && $s['FILE_DELETE']['p95'] > $th['file_ops']
        || isset($s['SESSION']) && $s['SESSION']['p95'] > $th['session'];
    $fsWrite = isset($s['FS_WRITE']) && $s['FS_WRITE']['p95'] > $th['fs_write'];
    $dbQuery = isset($s['DB_QUERY']) && $s['DB_QUERY']['p95'] > $th['db_query'];
    $dbConn = isset($s['DB_CONN']) && $s['DB_CONN']['p95'] > $th['db_connect'];
    $phpBad = isset($s['PHP']) && $s['PHP']['p95'] > $th['php'];
    $cpuBad = isset($s['LOAD_RATIO']) && $s['LOAD_RATIO']['p95'] > $th['load_ratio'];

    if ($storage && !$dbQuery) {
        return [
            'assessment' => 'Filesystem/storage latency is the strongest signal; database query execution remains comparatively normal.',
            'action' => 'Investigate CloudLinux I/O/IOPS limits, host storage contention, filesystem queueing, backups/scanning, and storage-layer throttling before changing MariaDB I/O settings.',
        ];
    }

    if ($fsWrite && !$storage && !$dbQuery) {
        return [
            'assessment' => 'Buffered filesystem write latency is elevated, but filesystem sync latency remains comparatively normal. This is a filesystem-write anomaly, not sufficient evidence by itself to identify a storage-device stall.',
            'action' => 'Correlate with FS_SYNC, external TTFB, CloudLinux I/O/IOPS limits, and host-side storage telemetry before attributing the event to physical storage.',
        ];
    }

    if ($dbQuery && !$storage) {
        return [
            'assessment' => 'MariaDB query execution latency is elevated without a matching filesystem signal.',
            'action' => 'Investigate SQL workload, locking, indexes, buffer-pool pressure, query plans, and database concurrency.',
        ];
    }

    if ($dbConn && !$dbQuery) {
        return [
            'assessment' => 'MariaDB connection-establishment latency is elevated while query execution remains normal. This does not indicate slow SQL.',
            'action' => 'Investigate connection concurrency, socket/TCP behavior, authentication overhead, process scheduling, and system/LVE contention before changing query or index configuration.',
        ];
    }

    if ($phpBad && !$storage && !$fsWrite && !$dbQuery && !$dbConn) {
        return [
            'assessment' => 'PHP execution is elevated while the basic filesystem and database tests remain comparatively normal.',
            'action' => 'Investigate application execution, PHP worker pressure, CloudLinux EP/NPROC/PMEM limits, and external application calls.',
        ];
    }

    if ($cpuBad && !$storage && !$fsWrite && !$dbQuery && !$dbConn) {
        return [
            'assessment' => 'Load ratio is elevated relative to the CPU visible to PHP.',
            'action' => 'Check CPU/LVE limits, process concurrency, and workload bursts; do not equate load ratio directly with CPU utilization.',
        ];
    }

    return [
        'assessment' => 'Multiple performance signals were elevated; the probe cannot establish a single root cause from this episode alone.',
        'action' => 'Correlate the episode with external TTFB, CloudLinux LVE statistics, OpenLiteSpeed activity, and host-side monitoring.',
    ];
}

$episodes = [];
$current = null;
$lastAbnormalTs = null;

foreach ($samples as $r) {
    $active = sampleFlags($r, $TH);
    $ts = timestampOf($r);

    if (!$active) {
        continue;
    }

    if ($current === null ||
        ($ts !== null && $lastAbnormalTs !== null && ($ts - $lastAbnormalTs) > $EPISODE_GAP_SECONDS)) {
        if ($current !== null) {
            $episodes[] = $current;
        }
        $current = [
            'start' => $r['time'] ?? 'UNKNOWN',
            'end' => $r['time'] ?? 'UNKNOWN',
            'count' => 0,
            'types' => [],
            'rows' => [],
        ];
    }

    $current['end'] = $r['time'] ?? $current['end'];
    $current['count']++;
    $current['rows'][] = $r;
    foreach ($active as $type) {
        $current['types'][$type] = ($current['types'][$type] ?? 0) + 1;
    }
    $lastAbnormalTs = $ts ?? $lastAbnormalTs;
}

if ($current !== null) {
    $episodes[] = $current;
}

foreach ($episodes as &$e) {
    arsort($e['types']);
    $e['primary'] = array_key_first($e['types']) ?? 'UNKNOWN';
    $startTs = timestampOf($e['rows'][0]);
    $endTs = timestampOf($e['rows'][count($e['rows']) - 1]);
    $e['duration'] = ($startTs !== null && $endTs !== null) ? max(0, $endTs - $startTs) : 0;
    $e['classification'] = classifyEpisode($e);
    $e['stats'] = episodeStats($e['rows']);

    $peak = 0.0;
    foreach ($e['rows'] as $row) {
        $peak = max($peak, sampleSeverity($row, $TH));
    }

    $persistence = min(25.0, max(0.0, ($e['count'] - 1) * 5.0));
    $breadth = min(15.0, max(0.0, (count($e['types']) - 1) * 5.0));
    $e['severity'] = (int)round(min(100.0, $peak + $persistence + $breadth));
    $e['diagnosis'] = diagnoseEpisode($e, $TH);
    $e['external'] = correlateExternal($e, $externalSamples);
}
unset($e);

usort($episodes, function (array $a, array $b): int {
    return ($b['severity'] <=> $a['severity']) ?: strcmp($b['start'], $a['start']);
});

$anomalies = array_values(array_filter($episodes, fn(array $e): bool => $e['classification'] === 'ISOLATED SPIKE'));
$incidents = array_values(array_filter($episodes, fn(array $e): bool => $e['classification'] !== 'ISOLATED SPIKE'));

// ============================================================
// STATISTICS
// ============================================================

$fsWrite =
    col(
        $samples,
        'fs_write'
    );

$fsSync =
    col(
        $samples,
        'fs_sync'
    );

$inode =
    col(
        $samples,
        'inode'
    );

$dbConn =
    col(
        $samples,
        'db_connect'
    );

$dbQuery =
    col(
        $samples,
        'db_query'
    );

$php =
    col(
        $samples,
        'php'
    );

$p95FsSync =
    percentile(
        $fsSync,
        95
    );

$p95Inode =
    percentile(
        $inode,
        95
    );

$p95DbConn =
    percentile(
        $dbConn,
        95
    );

$p95DbQry =
    percentile(
        $dbQuery,
        95
    );

$p95Php =
    percentile(
        $php,
        95
    );


$p95FsWrite = percentile($fsWrite, 95);

$storageHealthy =
    $p95FsSync < 50 &&
    $p95Inode < 10;

$dbQueryHealthy = $p95DbQry < 10;
$dbConnHealthy = $p95DbConn < 10;
$dbHealthy = $dbQueryHealthy && $dbConnHealthy;

$phpHealthy = $p95Php < 500;

$loadRatio = col($samples, 'load_ratio');
$p95LoadRatio = percentile($loadRatio, 95);

$maxMetrics = [
    'FS_WRITE' => $fsWrite ? max($fsWrite) : 0.0,
    'FS_SYNC' => $fsSync ? max($fsSync) : 0.0,
    'INODE' => $inode ? max($inode) : 0.0,
    'DB_CONN' => $dbConn ? max($dbConn) : 0.0,
    'DB_QUERY' => $dbQuery ? max($dbQuery) : 0.0,
    'PHP' => $php ? max($php) : 0.0,
];


// ============================================================
// REPORT
// ============================================================

$out = '';
$out .= "================ INCIDENT TIMELINE REPORT ================\n";
$out .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
$out .= "Samples: " . count($samples) . "\n";
$out .= "Range:   " . ($samples[0]['time'] ?? 'N/A') . " -> " . ($samples[count($samples) - 1]['time'] ?? 'N/A') . "\n\n";
$out .= "TOTAL INCIDENTS: " . count($incidents) . "\n";
$out .= "ISOLATED ANOMALIES: " . count($anomalies) . "\n\n";

$out .= "TOP INCIDENTS\n";
$out .= "----------------------------------------------------------\n";

if (!$incidents) {
    $out .= "No sustained or repeated incidents detected.\n";
    $out .= "Single-sample anomalies are reported separately below.\n";
} else {
    foreach (array_slice($incidents, 0, 10) as $e) {
        $out .= "[{$e['primary']} — {$e['classification']}]\n";
        $out .= "Start:       {$e['start']}\n";
        $out .= "End:         {$e['end']}\n";
        $out .= "Samples:     {$e['count']}\n";
        $out .= "Duration:    " . $e['duration'] . " sec\n";
        $out .= "Severity:    " . $e['severity'] . "/100\n";
        $out .= "Signals:     " . implode(' ', array_map(fn($k, $v) => "{$k}({$v})", array_keys($e['types']), array_values($e['types']))) . "\n\n";
        $out .= "MEASUREMENTS\n";
        $out .= sprintf("  %-12s %10s %10s %10s\n", 'Metric', 'AVG', 'P95', 'MAX');
        foreach ($e['stats'] as $name => $st) {
            $out .= sprintf("  %-12s %10.2f %10.2f %10.2f\n", $name, $st['avg'], $st['p95'], $st['max']);
        }
        if (!empty($e['external']['metrics'])) {
            $out .= "\nEXTERNAL HTTP CORRELATION\n";
            $out .= "  Samples matched: " . $e['external']['count'] . "\n";
            foreach ($e['external']['metrics'] as $name => $st) {
                $out .= sprintf("  %-12s %10.2f %10.2f %10.2f ms\n", $name, $st['avg'], $st['p95'], $st['max']);
            }
        }
        $out .= "\nASSESSMENT\n  {$e['diagnosis']['assessment']}\n";
        $out .= "ACTION\n  {$e['diagnosis']['action']}\n";
        $out .= "----------------------------------------------------------\n";
    }
}

if ($anomalies) {
    $out .= "\nISOLATED ANOMALIES / SPIKES\n";
    $out .= "----------------------------------------------------------\n";
    foreach (array_slice($anomalies, 0, 20) as $e) {
        $out .= "[{$e['primary']} — ISOLATED SPIKE]\n";
        $out .= "Time:        {$e['start']}\n";
        $out .= "Severity:    {$e['severity']}/100\n";
        $out .= "Signals:     " . implode(' ', array_keys($e['types'])) . "\n";
        foreach ($e['stats'] as $name => $st) {
            $out .= sprintf("  %-12s AVG=%8.2f  P95=%8.2f  MAX=%8.2f\n", $name, $st['avg'], $st['p95'], $st['max']);
        }
        $out .= "  Assessment: {$e['diagnosis']['assessment']}\n";
        $out .= "  Action:     {$e['diagnosis']['action']}\n";
        $out .= "----------------------------------------------------------\n";
    }
}

// ============================================================
// SUMMARY
// ============================================================

$out .=
    "\nSUMMARY STATISTICS\n";

$out .=
    "==========================================================\n";

$metrics = [
    'FS_WRITE' => $fsWrite,
    'FS_SYNC' => $fsSync,
    'INODE' => $inode,
    'DB_CONN' => $dbConn,
    'DB_QUERY' => $dbQuery,
    'PHP' => $php,
    'LOAD_RATIO' => $loadRatio,
];

foreach ($metrics as $name => $values) {
    $out .= sprintf(
        "%-10s Avg=%8.2f  P50=%8.2f  P95=%8.2f  MAX=%8.2f\n",
        $name,
        avg($values),
        percentile($values, 50),
        percentile($values, 95),
        $values ? max($values) : 0.0
    );
}



// ============================================================
// HEALTH
// ============================================================

$out .=
    "\nHEALTH ASSESSMENT\n";

$out .=
    "==========================================================\n";

$out .=
    "Storage:    " .
    (
        $storageHealthy
            ? 'HEALTHY'
            : 'DEGRADED'
    ) .
    "\n";

$out .=
    "Database:   " .
    (
        $dbHealthy
            ? 'HEALTHY'
            : 'DEGRADED'
    ) .
    "\n";

$out .=
    "PHP:        " .
    (
        $phpHealthy
            ? 'HEALTHY'
            : 'DEGRADED'
    ) .
    "\n";

$out .= "DB query execution: " . ($dbQueryHealthy ? 'HEALTHY' : 'DEGRADED') . "\n";
$out .= "DB connection latency: " . ($dbConnHealthy ? 'HEALTHY' : 'DEGRADED') . "\n";


// ============================================================
// ROOT CAUSE
// ============================================================

$out .= "\nLIKELY ROOT CAUSE\n";
$out .= "==========================================================\n";

if (!$storageHealthy) {
    $out .= "Filesystem/storage latency appears to be the dominant systemic signal.\n";
    $out .= "Database query execution remains " . ($dbQueryHealthy ? "normal" : "elevated") . ".\n";
    $out .= "Pattern is consistent with intermittent storage contention, CloudLinux I/O/IOPS throttling, network-backed storage latency, or filesystem queue congestion.\n";
} elseif (!$dbQueryHealthy) {
    $out .= "MariaDB query execution latency is elevated.\n";
    $out .= "Investigate SQL workload, locking, indexes, buffer-pool pressure, and query concurrency.\n";
} elseif (!$dbConnHealthy) {
    $out .= "MariaDB connection-establishment latency is elevated while query execution remains normal.\n";
    $out .= "This does not indicate slow SQL. Investigate connection concurrency, socket/TCP behavior, authentication, scheduling, and LVE/system contention.\n";
} elseif (!$phpHealthy) {
    $out .= "PHP latency exceeds expected values despite healthy basic filesystem and database query metrics.\n";
    $out .= "Investigate application execution, PHP worker limits, external calls, and request concurrency.\n";
} else {
    $out .= "No dominant systemic bottleneck identified from the aggregate thresholds.\n";
}

// ============================================================
// SERVER DISCOVERY
// ============================================================

$out .=
    "\nSERVER DISCOVERY\n";

$out .=
    "==========================================================\n";

if (!$serverInfo) {

    $out .=
        "No server information is available.\n";

    $out .=
        "Run the refactored probe.php to collect "
        .
        "hardware and configuration information.\n";

} else {

    $cpu =
        $serverInfo['cpu']
        ?? [];

    $memory =
        $serverInfo['memory']
        ?? [];

    $storage =
        $serverInfo['storage']
        ?? [];

    $os =
        $serverInfo['os']
        ?? [];

    $cg =
        $serverInfo['cgroup']
        ?? [];

    $cloudlinux =
        $serverInfo['cloudlinux']
        ?? [];

    $lve =
        $cloudlinux['lve']
        ?? [];

    $ols =
        $serverInfo['openlitespeed']
        ?? [];

    $phpInfo =
        $serverInfo['php']
        ?? [];

    $maria =
        $serverInfo['mariadb']
        ?? [];

    $ramBytes =
        $memory['total_bytes']
        ?? null;

    $ramGiB =
        bytesToGiB(
            $ramBytes
        );

    // Correct/annotate common topology ambiguity in /proc/cpuinfo.
    // AMD EPYC model names identify physical core count; when the
    // reported logical count is approximately 2x that value, use the
    // model-derived topology for presentation and label it derived.
    $displayPhysicalCores = $cpu['physical_cores'] ?? null;
    $displayThreads = $cpu['threads_per_core'] ?? null;
    $modelDerivedTopology = false;
    if (isset($cpu['model'], $cpu['logical_cpus']) && preg_match('/\b(\d+)-Core Processor\b/i', (string)$cpu['model'], $m)) {
        $modelCores = (int)$m[1];
        $logicalCpuCount = (int)$cpu['logical_cpus'];
        if ($modelCores > 0 && $logicalCpuCount >= ($modelCores * 2) && $logicalCpuCount <= ($modelCores * 4)) {
            $displayPhysicalCores = $modelCores;
            $displayThreads = max(1, (int)round($logicalCpuCount / $modelCores));
            $modelDerivedTopology = true;
        }
    }

    $out .=
        "CPU model:             " .
        ($cpu['model'] ?? 'N/A') .
        "\n";

    $out .=
        "Logical CPUs visible:  " .
        ($cpu['logical_cpus'] ?? 'N/A') .
        "\n";

    $out .=
        "Physical cores:        " .
        ($displayPhysicalCores ?? 'NOT VISIBLE') .
        "\n";

    $out .=
        "CPU sockets:            " .
        ($cpu['sockets'] ?? 'NOT VISIBLE') .
        "\n";

    $out .=
        "Threads/core:           " .
        ($displayThreads ?? 'N/A') .
        "\n";

    if ($modelDerivedTopology) {
        $out .= "CPU topology note:      physical cores/threads derived from CPU model name and logical CPU count.\n";
    }

    $out .=
        "RAM visible:            " .
        fmtBytes($ramBytes) .
        "\n";

    $out .=
        "Storage type:           " .
        ($storage['primary_type'] ?? 'NOT DETECTED') .
        "\n";

    $out .=
        "Operating system:       " .
        ($os['os'] ?? 'N/A') .
        "\n";

    $out .=
        "Kernel:                 " .
        ($os['kernel'] ?? 'N/A') .
        "\n";

    $out .=
        "PHP:                    " .
        ($phpInfo['version'] ?? 'N/A') .
        "\n";

    $out .=
        "OpenLiteSpeed:          " .
        (
            ($ols['detected'] ?? false)
                ? 'DETECTED'
                : 'NOT CONFIRMED'
        ) .
        "\n";

    $out .=
        "MariaDB:                " .
        ($maria['version'] ?? 'N/A') .
        "\n";

    $out .=
        "Discovery timestamp:    " .
        ($serverInfo['collected_at'] ?? 'N/A') .
        "\n";
}


// ============================================================
// CLOUDLINUX / LVE
// ============================================================

$out .=
    "\nCLOUDLINUX / LVE STATUS\n";

$out .=
    "----------------------------------------------------------\n";

if (!$serverInfo) {

    $out .=
        "CloudLinux status:     UNKNOWN\n";

} else {

    $status =
        $cloudlinux['status']
        ?? 'unknown';

    if ($status === 'detected') {

        $out .=
            "CloudLinux status:     DETECTED\n";

    } elseif (
        $status === 'not_confirmed'
    ) {

        $out .=
            "CloudLinux status:     NOT CONFIRMED\n";

        $out .=
            "This does NOT mean CloudLinux is absent. "
            .
            "The PHP environment did not expose a "
            .
            "definitive CloudLinux indicator.\n";

    } else {

        $out .=
            "CloudLinux status:     UNKNOWN\n";
    }

    $indicators =
        $os['indicators']
        ?? [];

    if ($indicators) {

        $out .=
            "Detection indicators:  " .
            implode(
                ', ',
                $indicators
            ) .
            "\n";
    }

    $out .=
        "LVE tooling detected:   " .
        (
            ($lve['detected'] ?? false)
                ? 'YES'
                : 'NO'
        ) .
        "\n";

    $out .=
        "LVE information access: " .
        (
            ($lve['accessible'] ?? false)
                ? 'YES'
                : 'NO'
        ) .
        "\n";

    if (
        !empty(
            $lve['limits']
        )
    ) {

        $out .=
            "Parsed LVE limits:     YES\n";

        foreach (
            $lve['limits']
            as $key => $value
        ) {

            $out .=
                sprintf(
                    "  %-20s %s\n",
                    strtoupper($key) . ':',
                    (string)$value
                );
        }

    } else {

        $out .=
            "Parsed LVE limits:     NOT AVAILABLE\n";
    }

    $out .=
        "Cgroup available:      " .
        (
            ($cg['available'] ?? false)
                ? 'YES'
                : 'NO'
        ) .
        "\n";

    if (
        ($cg['available'] ?? false)
    ) {

        $out .=
            "Cgroup version:        " .
            ($cg['version'] ?? 'N/A') .
            "\n";

        $out .=
            "Cgroup CPU limit:      " .
            (
                $cg['cpu_limit_cpus'] !== null
                    ? $cg['cpu_limit_cpus'] .
                      ' CPUs'
                    : 'not restricted/not visible'
            ) .
            "\n";

        $out .=
            "Cgroup memory limit:   " .
            fmtBytes(
                $cg['memory_limit_bytes']
                ?? null
            ) .
            "\n";

        $out .=
            "Cgroup PID limit:      " .
            (
                $cg['pids_limit'] !== null
                    ? (string)$cg['pids_limit']
                    : 'not restricted/not visible'
            ) .
            "\n";
    }
}


// ============================================================
// CONFIGURATION RECOMMENDATIONS
// ============================================================

$out .=
    "\nCONFIGURATION RECOMMENDATIONS\n";

$out .=
    "==========================================================\n";


// ============================================================
// CLOUDLINUX RECOMMENDATIONS
// ============================================================

$out .=
    "\nCLOUDLINUX / OPENLITESPEED RECOMMENDATIONS\n";

$out .=
    "----------------------------------------------------------\n";

if (!$serverInfo) {

    $out .=
        "Insufficient discovery information.\n";

} else {

    $cloudStatus =
        $cloudlinux['status']
        ?? 'unknown';

    $lveAccessible =
        $lve['accessible']
        ?? false;

    $logical =
        $cpu['logical_cpus']
        ?? null;

    if (
        $cloudStatus === 'detected'
    ) {

        $out .=
            "CloudLinux is positively identified.\n";

        if ($lveAccessible) {

            $out .=
                "LVE tooling is accessible. Use the "
                .
                "reported LVE values as the account-level "
                .
                "resource baseline.\n";

        } else {

            $out .=
                "CloudLinux is identified, but LVE limits "
                .
                "cannot be queried from this PHP environment.\n";

            $out .=
                "Do NOT infer CPU, PMEM, EP, NPROC, IO or "
                .
                "IOPS limits from the absence of cgroup data.\n";

            $out .=
                "Obtain those values from CloudLinux/LVE "
                .
                "Manager or the hosting provider before "
                .
                "changing account limits.\n";
        }

    } elseif (
        $cloudStatus === 'not_confirmed'
    ) {

        $out .=
            "CloudLinux could not be positively confirmed "
            .
            "from the PHP environment.\n";

        $out .=
            "Do not conclude that CloudLinux is absent. "
            .
            "If this is known to be a CloudLinux server, "
            .
            "the host is hiding the relevant indicators "
            .
            "from PHP.\n";

    } else {

        $out .=
            "CloudLinux status is unknown.\n";
    }

    /*
     * CPU recommendation.
     */
    if ($logical !== null) {

        $out .=
            "CPU guidance: PHP sees " .
            $logical .
            " logical CPU(s). This is an execution-environment "
            .
            "value and must not automatically be interpreted "
            .
            "as the physical host CPU count.\n";

        if (
            $lveAccessible &&
            isset(
                $lve['limits']['cpu']
            )
        ) {

            $lveCpu =
                (float)$lve['limits']['cpu'];

            $out .=
                "LVE CPU: " .
                $lveCpu .
                ". Compare this with sustained load ratio "
                .
                "before increasing the limit.\n";
        }
    }

    /*
     * EP.
     */
    if (
        $lveAccessible &&
        isset(
            $lve['limits']['ep']
        )
    ) {

        $ep =
            (int)$lve['limits']['ep'];

        $out .=
            "EP: " .
            $ep .
            ". Review alongside request concurrency and "
            .
            "OpenLiteSpeed external application queues.\n";

    } else {

        $out .=
            "EP: not accessible. Review in LVE Manager.\n";
    }

    /*
     * NPROC.
     */
    if (
        $lveAccessible &&
        isset(
            $lve['limits']['nproc']
        )
    ) {

        $nproc =
            (int)$lve['limits']['nproc'];

        $out .=
            "NPROC: " .
            $nproc .
            ". Ensure it can accommodate PHP/OpenLiteSpeed "
            .
            "processes plus cron and application activity.\n";

    } else {

        $out .=
            "NPROC: not accessible. Review in LVE Manager.\n";
    }

    /*
     * IO.
     */
    if (
        $lveAccessible &&
        isset(
            $lve['limits']['io']
        )
    ) {

        $out .=
            "I/O limit: " .
            $lve['limits']['io'] .
            ". Compare this with observed FS_WRITE and "
            .
            "FS_SYNC latency before changing MariaDB I/O "
            .
            "parameters.\n";

    } else {

        $out .=
            "I/O limit: not accessible. This is especially "
            .
            "important because filesystem latency is part "
            .
            "of the current health assessment.\n";
    }

    /*
     * IOPS.
     */
    if (
        $lveAccessible &&
        isset(
            $lve['limits']['iops']
        )
    ) {

        $out .=
            "IOPS limit: " .
            $lve['limits']['iops'] .
            ". Compare with workload and storage latency.\n";

    } else {

        $out .=
            "IOPS limit: not accessible. Review in LVE Manager.\n";
    }

    /*
     * Memory.
     */
    if (
        $lveAccessible &&
        isset(
            $lve['limits']['pmem']
        )
    ) {

        $out .=
            "PMEM: " .
            $lve['limits']['pmem'] .
            ". PHP worker sizing should remain below this "
            .
            "limit with sufficient headroom for concurrent "
            .
            "requests.\n";

    } else {

        $out .=
            "PMEM: not accessible. Do not increase PHP "
            .
            "worker concurrency without confirming the "
            .
            "account's memory limit.\n";
    }

    /*
     * Storage-specific warning.
     */
    if (!$storageHealthy || $p95FsWrite > $TH['fs_write']) {
        $out .=
            "\nPRIORITY: Filesystem/write latency is degraded or intermittently elevated. "
            .
            "Check CloudLinux I/O and IOPS limits before changing MariaDB "
            .
            "I/O or memory settings.\n";
    }
}


// ============================================================
// MARIADB RECOMMENDATIONS
// ============================================================

$out .=
    "\nMARIADB RECOMMENDATIONS\n";

$out .=
    "----------------------------------------------------------\n";

if (
    !$serverInfo ||
    empty(
        $maria['available']
    )
) {

    $out .=
        "MariaDB configuration could not be queried.\n";

} else {

    $ramGiB =
        bytesToGiB(
            $memory['total_bytes']
            ?? null
        );

    $bp =
        bytesToGiB(
            mariaVar(
                $serverInfo,
                'innodb_buffer_pool_size'
            )
        );

    $maxConn =
        (int)(
            mariaVar(
                $serverInfo,
                'max_connections'
            )
            ?? 0
        );

    $ioCap =
        (int)(
            mariaVar(
                $serverInfo,
                'innodb_io_capacity'
            )
            ?? 0
        );

    $ioMax =
        (int)(
            mariaVar(
                $serverInfo,
                'innodb_io_capacity_max'
            )
            ?? 0
        );

    $tmp =
        bytesToGiB(
            mariaVar(
                $serverInfo,
                'tmp_table_size'
            )
        );

    $heap =
        bytesToGiB(
            mariaVar(
                $serverInfo,
                'max_heap_table_size'
            )
        );

    $flush =
        mariaVar(
            $serverInfo,
            'innodb_flush_log_at_trx_commit'
        );

    $method =
        mariaVar(
            $serverInfo,
            'innodb_flush_method'
        );

    $queryCache =
        mariaVar(
            $serverInfo,
            'query_cache_size'
        );


    /*
     * --------------------------------------------------------
     * BUFFER POOL
     * --------------------------------------------------------
     */

    if ($ramGiB !== null) {

        /*
         * Shared-hosting starting point.
         *
         * This is intentionally lower than a dedicated
         * database server recommendation because the machine
         * also runs OpenLiteSpeed/PHP and other hosted sites.
         */
        if ($ramGiB <= 8) {
            $target = $ramGiB * 0.40;
        } elseif ($ramGiB <= 32) {
            $target = $ramGiB * 0.50;
        } else {
            // Shared-server cap: physical RAM alone is not a safe basis
            // for allocating hundreds of GiB to MariaDB.
            $target = min($ramGiB * 0.25, 32.0);
        }

        $low = $target * 0.75;
        $high = $target * 1.10;

        $out .=
            "InnoDB buffer pool:\n";

        $out .=
            "  Detected: " .
            fmtNumber($bp, 2) .
            " GiB\n";

        $out .=
            "  Shared-server starting range: " .
            fmtNumber($low, 1) .
            "–" .
            fmtNumber($high, 1) .
            " GiB\n";
        $out .=
            "  Important: physical RAM alone is NOT a safe target on a shared web server.\n";

        if (
            $bp !== null &&
            $bp < $low
        ) {

            if (!$storageHealthy) {

                $out .=
                    "  Assessment: buffer pool appears "
                    .
                    "conservative, but increasing it is "
                    .
                    "NOT the first recommendation because "
                    .
                    "storage latency is currently degraded.\n";

            } else {

                $out .=
                    "  Assessment: buffer pool appears "
                    .
                    "conservative relative to detected RAM. "
                    .
                    "Consider increasing gradually while "
                    .
                    "monitoring total memory consumption.\n";
            }

        } elseif (
            $bp !== null &&
            $bp > $high
        ) {

            $out .=
                "  Assessment: buffer pool is already large "
                .
                "relative to the shared-hosting starting range. "
                .
                "Do not increase it without workload evidence.\n";

        } else {

            $out .=
                "  Assessment: buffer pool is within the "
                .
                "calculated starting range.\n";
        }

    } else {

        $out .=
            "InnoDB buffer pool: host-visible RAM unavailable; "
            .
            "no hardware-based recommendation generated.\n";
    }


    /*
     * --------------------------------------------------------
     * MAX CONNECTIONS
     * --------------------------------------------------------
     */

    $out .=
        "\nmax_connections:\n";

    $out .=
        "  Current: " .
        (
            $maxConn > 0
                ? $maxConn
                : 'N/A'
        ) .
        "\n";

    if ($maxConn > 300) {

        $out .=
            "  Assessment: relatively high for shared "
            .
            "hosting. Increasing this further can increase "
            .
            "memory pressure substantially.\n";

    } elseif (
        $maxConn > 0
    ) {

        $out .=
            "  Assessment: no obvious concern from the "
            .
            "configured value alone. Validate against "
            .
            "actual connection concurrency before changing it.\n";
    }


    /*
     * --------------------------------------------------------
     * INNODB IO
     * --------------------------------------------------------
     */

    $out .=
        "\nInnoDB I/O capacity:\n";

    $out .=
        "  innodb_io_capacity:     " .
        (
            $ioCap > 0
                ? $ioCap
                : 'N/A'
        ) .
        "\n";

    $out .=
        "  innodb_io_capacity_max: " .
        (
            $ioMax > 0
                ? $ioMax
                : 'N/A'
        ) .
        "\n";

    if (!$storageHealthy) {

        $out .=
            "  Assessment: do NOT raise these values simply "
            .
            "because the storage is slow. The probe indicates "
            .
            "filesystem latency, which may represent host/LVE "
            .
            "I/O contention rather than insufficient MariaDB "
            .
            "I/O capacity.\n";

    } elseif (
        $ioCap > 0
    ) {

        $out .=
            "  Assessment: values should be tuned according "
            .
            "to actual storage capability and database "
            .
            "workload. NVMe can justify substantially higher "
            .
            "values than rotational storage, but measurement "
            .
            "should drive the change.\n";
    }


    /*
     * --------------------------------------------------------
     * PER-CONNECTION MEMORY
     * --------------------------------------------------------
     */

    $out .=
        "\nPer-operation memory:\n";

    $out .=
        "  tmp_table_size:       " .
        fmtNumber($tmp, 2) .
        " GiB\n";

    $out .=
        "  max_heap_table_size:  " .
        fmtNumber($heap, 2) .
        " GiB\n";

    $out .=
        "  Recommendation: avoid unnecessarily large values. "
        .
        "These settings can contribute to significant memory "
        .
        "consumption under concurrent workloads.\n";


    /*
     * --------------------------------------------------------
     * DURABILITY
     * --------------------------------------------------------
     */

    $out .=
        "\nDurability:\n";

    $out .=
        "  innodb_flush_log_at_trx_commit: " .
        ($flush ?? 'N/A') .
        "\n";

    $out .=
        "  innodb_flush_method: " .
        ($method ?? 'N/A') .
        "\n";

    $out .=
        "  Recommendation: preserve transactional durability "
        .
        "unless there is an explicit, understood trade-off "
        .
        "being made. Do not use durability changes as a "
        .
        "generic response to filesystem latency.\n";


    /*
     * --------------------------------------------------------
     * QUERY CACHE
     * --------------------------------------------------------
     */

    $out .=
        "\nQuery cache:\n";

    $out .=
        "  query_cache_size: " .
        ($queryCache ?? 'N/A') .
        "\n";

    if (
        $queryCache !== null &&
        (int)$queryCache > 0
    ) {

        $out .=
            "  Assessment: query cache is non-zero. Review "
            .
            "whether it provides measurable benefit for the "
            .
            "actual workload.\n";

    } else {

        $out .=
            "  Assessment: query cache appears disabled or "
            .
            "unavailable.\n";
    }
}


// ============================================================
// CROSS-SYSTEM DIAGNOSTIC
// ============================================================

$out .= "\nCROSS-SYSTEM DIAGNOSTIC\n";
$out .= "----------------------------------------------------------\n";

if (!$storageHealthy) {
    $out .= "The strongest aggregate signal is filesystem/storage latency, while database query execution is " . ($dbQueryHealthy ? 'healthy' : 'also elevated') . ".\n";
    $out .= "Priority order:\n";
    $out .= "  1. Verify CloudLinux I/O and IOPS limits.\n";
    $out .= "  2. Check for host/storage contention.\n";
    $out .= "  3. Determine whether storage is local NVMe/SSD or network-backed.\n";
    $out .= "  4. Correlate filesystem incidents with external TTFB.\n";
    $out .= "  5. Only then consider MariaDB I/O tuning.\n";
} elseif ($p95FsWrite > $TH['fs_write']) {
    $out .= "Buffered filesystem writes are intermittently elevated, but aggregate FS_SYNC remains comparatively healthy. Treat this as a filesystem-write anomaly until external/host telemetry confirms a storage stall.\n";
} elseif (!$dbQueryHealthy) {
    $out .= "MariaDB query execution is elevated without a matching aggregate storage signal. Review SQL workload, locking, indexes, and buffer-pool behavior.\n";
} elseif (!$dbConnHealthy) {
    $out .= "MariaDB connection establishment is elevated while query execution remains normal. This is a connection-path problem, not evidence of slow SQL.\n";
} elseif (!$phpHealthy) {
    $out .= "PHP execution is elevated while storage and database query tests remain healthy. Review PHP application execution, EP/NPROC/PMEM, and external calls.\n";
} else {
    $out .= "No dominant systemic bottleneck was detected by aggregate thresholds. Long-tail anomalies should still be reviewed below.\n";
}

// Long-tail pattern analysis.
$maxFsWrite = $fsWrite ? max($fsWrite) : 0.0;
$fsWriteP50 = percentile($fsWrite, 50);
$maxRatio = ($fsWriteP50 > 0) ? ($maxFsWrite / $fsWriteP50) : 0.0;

$out .= "\nPERFORMANCE PATTERN\n";
$out .= "----------------------------------------------------------\n";
if ($maxRatio >= 100.0 || count($incidents) >= 1) {
    $out .= "The sampled data shows a long-tail latency pattern: typical performance can be normal while intermittent outliers are orders of magnitude slower.\n";
    if ($fsWriteP50 > 0) {
        $out .= sprintf("FS_WRITE P50: %.2f ms | MAX: %.2f ms | MAX/P50: %.1fx\n", $fsWriteP50, $maxFsWrite, $maxRatio);
    }
    $out .= "Review incident timestamps against CloudLinux LVE I/O/IOPS data, host storage telemetry, backups/scanning, and external TTFB.\n";
} else {
    $out .= "No extreme long-tail filesystem pattern was identified in the sampled period.\n";
}

if ($externalSamples) {
    $out .= "External HTTP samples available: " . count($externalSamples) . ". Incident correlation is reported where timestamps overlap.\n";
} else {
    $out .= "External HTTP metrics: not available. Add external_metrics.ndjson to correlate internal stalls with externally observed TTFB.\n";
}

// ============================================================
// METRIC REFERENCE
// ============================================================

$out .=
    "\nMETRIC REFERENCE\n";

$out .=
    "==========================================================\n";

$out .= <<<TXT
FS_WRITE
  Buffered filesystem write latency.

FS_SYNC
  Time required to flush writes to storage.

INODE
  Filesystem metadata create/delete latency.

DB_CONN
  Time required to establish the configured
  MariaDB connection. Elevated DB_CONN with normal
  DB_QUERY indicates connection-path latency, not slow SQL.

DB_QUERY
  Execution time of a trivial SELECT 1 query.

PHP
  Total probe execution time.

LOAD_RATIO
  System load divided by the CPU count visible
  to the PHP environment.

  This is a workload indicator, not a direct
  CPU-utilization percentage.

SERVER_INFO
  Hardware, software, CloudLinux/LVE, cgroup,
  OpenLiteSpeed and MariaDB discovery information.

IMPORTANT
  "CPU visible to PHP" and "RAM visible to PHP"
  are not automatically equivalent to physical
  bare-metal resources.

  CloudLinux/LVE limits may be imposed independently
  of generic Linux cgroups.

TXT;


// ============================================================
// HTML OUTPUT
// ============================================================

$html =
    "<!doctype html>" .
    "<html>" .
    "<head>" .
    "<meta charset='utf-8'>" .
    "<meta name='viewport' content='width=device-width,initial-scale=1'>" .
    "<title>Server Health Report</title>" .
    "<style>" .
    "body{background:#f5f5f5;color:#222;margin:0;padding:20px}" .
    "pre{background:#fff;padding:20px;border:1px solid #ccc;" .
    "border-radius:6px;overflow:auto;font-family:monospace;" .
    "font-size:13px;line-height:1.45;white-space:pre-wrap}" .
    "</style>" .
    "</head>" .
    "<body>";

$html .=
    "<pre>" .
    htmlspecialchars(
        $out,
        ENT_QUOTES |
        ENT_SUBSTITUTE,
        'UTF-8'
    ) .
    "</pre>";

$html .=
    "</body>" .
    "</html>";

@file_put_contents(
    $outHtml,
    $html,
    LOCK_EX
);


// ============================================================
// CLI OUTPUT
// ============================================================

echo $out;
