<?php

# probe.php

declare(strict_types=1);

date_default_timezone_set('UTC');

$logfile        = __DIR__ . '/probe_metrics.ndjson';
$serverInfoFile = __DIR__ . '/server_info.json';


// ============================================================
// GENERAL UTILITIES
// ============================================================

function ms(float $start): float
{
    return round((microtime(true) - $start) * 1000, 3);
}


function avg(array $values): ?float
{
    return $values
        ? round(array_sum($values) / count($values), 3)
        : null;
}


function readFileTrimmed(string $file): ?string
{
    if (!is_readable($file)) {
        return null;
    }

    $value = @file_get_contents($file);

    if ($value === false) {
        return null;
    }

    $value = trim($value);

    return $value === '' ? null : $value;
}


function readFirstLine(string $file): ?string
{
    if (!is_readable($file)) {
        return null;
    }

    $lines = @file(
        $file,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    return $lines && isset($lines[0])
        ? trim($lines[0])
        : null;
}


function commandPath(string $command): ?string
{
    $path = @shell_exec(
        'command -v ' .
        escapeshellarg($command) .
        ' 2>/dev/null'
    );

    $path = trim((string)$path);

    return $path !== '' ? $path : null;
}


function runCommand(
    string $command,
    int $timeout = 2
): ?string {
    /*
     * timeout is intentionally advisory. Some shared hosts
     * do not provide the timeout utility, so fall back to
     * ordinary shell execution.
     */
    $prefix = '';

    if (
        $timeout > 0 &&
        commandPath('timeout')
    ) {
        $prefix =
            'timeout ' .
            (int)$timeout .
            's ';
    }

    $output = @shell_exec(
        $prefix .
        $command .
        ' 2>/dev/null'
    );

    if ($output === null) {
        return null;
    }

    $output = trim($output);

    return $output === ''
        ? null
        : $output;
}


function commandExists(string $command): bool
{
    return commandPath($command) !== null;
}


function safeInt(?string $value): ?int
{
    if (
        $value === null ||
        !is_numeric($value)
    ) {
        return null;
    }

    return (int)$value;
}


// ============================================================
// CPU DISCOVERY
// ============================================================

function getCpuInfo(): array
{
    $result = [
        'logical_cpus'          => null,
        'physical_cores'        => null,
        'sockets'               => null,
        'threads_per_core'      => null,
        'model'                 => null,
        'architecture'          => php_uname('m') ?: null,
        'source'                => [],
    ];

    $contents = null;

    if (is_readable('/proc/cpuinfo')) {
        $contents = @file_get_contents('/proc/cpuinfo');
    }

    if ($contents !== false && $contents !== null) {

        $logical =
            preg_match_all(
                '/^processor\s*:/m',
                $contents
            );

        if ($logical > 0) {
            $result['logical_cpus'] = $logical;
            $result['source'][] = '/proc/cpuinfo';
        }

        if (
            preg_match_all(
                '/^model name\s*:\s*(.+)$/m',
                $contents,
                $m
            )
        ) {
            $models =
                array_values(
                    array_unique(
                        array_map(
                            'trim',
                            $m[1]
                        )
                    )
                );

            $result['model'] =
                $models[0] ?? null;
        }

        /*
         * Determine physical topology where the kernel exposes
         * physical_id/core_id.
         */
        $pairs = [];
        $sockets = [];

        $blocks =
            preg_split(
                "/\n\s*\n/",
                trim($contents)
            );

        foreach ($blocks as $block) {

            $physicalId = null;
            $coreId     = null;

            if (
                preg_match(
                    '/^physical id\s*:\s*(\d+)/m',
                    $block,
                    $m
                )
            ) {
                $physicalId = $m[1];
                $sockets[] = $physicalId;
            }

            if (
                preg_match(
                    '/^core id\s*:\s*(\d+)/m',
                    $block,
                    $m
                )
            ) {
                $coreId = $m[1];
            }

            if (
                $physicalId !== null &&
                $coreId !== null
            ) {
                $pairs[] =
                    $physicalId .
                    ':' .
                    $coreId;
            }
        }

        if ($pairs) {
            $result['physical_cores'] =
                count(array_unique($pairs));
        }

        if ($sockets) {
            $result['sockets'] =
                count(array_unique($sockets));
        }
    }

    /*
     * nproc may reflect a CPU restriction rather than the
     * physical machine. Therefore label it as visible CPU,
     * not physical CPU.
     */
    if (
        $result['logical_cpus'] === null &&
        commandExists('nproc')
    ) {
        $nproc =
            safeInt(
                runCommand('nproc')
            );

        if ($nproc !== null && $nproc > 0) {
            $result['logical_cpus'] = $nproc;
            $result['source'][] = 'nproc';
        }
    }

    /*
     * sysconf() is another fallback for visible CPUs.
     */
    if (
        $result['logical_cpus'] === null &&
        function_exists('shell_exec')
    ) {
        $sysconf =
            safeInt(
                runCommand(
                    'getconf _NPROCESSORS_ONLN'
                )
            );

        if ($sysconf !== null && $sysconf > 0) {
            $result['logical_cpus'] = $sysconf;
            $result['source'][] = 'getconf';
        }
    }

    /*
     * Do NOT silently equate logical CPUs with physical cores.
     * If topology is unavailable, physical_cores remains null.
     */
    if (
        $result['physical_cores'] !== null &&
        $result['logical_cpus'] !== null &&
        $result['physical_cores'] > 0
    ) {
        $result['threads_per_core'] =
            round(
                $result['logical_cpus'] /
                $result['physical_cores'],
                2
            );
    }

    return $result;
}


// ============================================================
// MEMORY DISCOVERY
// ============================================================

function readMemInfo(): array
{
    $result = [];

    if (!is_readable('/proc/meminfo')) {
        return $result;
    }

    $lines =
        @file(
            '/proc/meminfo',
            FILE_IGNORE_NEW_LINES |
            FILE_SKIP_EMPTY_LINES
        ) ?: [];

    foreach ($lines as $line) {

        if (
            preg_match(
                '/^([^:]+):\s+(\d+)\s*(kB|MB|GB)?$/i',
                $line,
                $m
            )
        ) {

            $key =
                trim($m[1]);

            $value =
                (float)$m[2];

            $unit =
                strtolower(
                    $m[3] ?? 'kb'
                );

            switch ($unit) {

                case 'gb':
                    $value *= 1024 * 1024 * 1024;
                    break;

                case 'mb':
                    $value *= 1024 * 1024;
                    break;

                default:
                    $value *= 1024;
            }

            $result[$key] =
                (int)$value;
        }
    }

    return $result;
}


function getMemoryInfo(): array
{
    $m = readMemInfo();

    return [
        'total_bytes' =>
            $m['MemTotal'] ?? null,

        'available_bytes' =>
            $m['MemAvailable'] ?? null,

        'free_bytes' =>
            $m['MemFree'] ?? null,

        'swap_total_bytes' =>
            $m['SwapTotal'] ?? null,

        'swap_free_bytes' =>
            $m['SwapFree'] ?? null,

        'source' =>
            is_readable('/proc/meminfo')
                ? '/proc/meminfo'
                : null,
    ];
}


// ============================================================
// STORAGE DISCOVERY
// ============================================================

function getStorageInfo(): array
{
    $devices = [];

    if (!is_dir('/sys/block')) {
        return [
            'devices'      => [],
            'primary_type' => null,
            'source'       => null,
        ];
    }

    foreach (
        glob('/sys/block/*') ?: []
        as $path
    ) {

        $name =
            basename($path);

        /*
         * Ignore pseudo-devices.
         */
        if (
            preg_match(
                '/^(loop|ram|fd|sr|zram|dm-|md)/',
                $name
            )
        ) {
            continue;
        }

        $size =
            readFirstLine(
                $path . '/size'
            );

        $rotational =
            readFirstLine(
                $path .
                '/queue/rotational'
            );

        $model =
            readFirstLine(
                $path .
                '/device/model'
            );

        $vendor =
            readFirstLine(
                $path .
                '/device/vendor'
            );

        $type = null;

        if (
            str_starts_with(
                strtolower($name),
                'nvme'
            )
        ) {
            $type = 'NVMe';

        } elseif (
            $rotational !== null
        ) {
            $type =
                ((int)$rotational === 0)
                    ? 'SSD/non-rotational'
                    : 'HDD/rotational';
        }

        $devices[] = [

            'name' =>
                $name,

            'model' =>
                $model,

            'vendor' =>
                $vendor,

            'type' =>
                $type,

            'bytes' =>
                $size !== null
                    ? ((int)$size * 512)
                    : null,

            'rotational' =>
                $rotational !== null
                    ? (int)$rotational
                    : null,
        ];
    }

    $types =
        array_values(
            array_filter(
                array_unique(
                    array_column(
                        $devices,
                        'type'
                    )
                )
            )
        );

    $primary = null;

    if (
        in_array(
            'NVMe',
            $types,
            true
        )
    ) {
        $primary = 'NVMe';

    } elseif (
        in_array(
            'SSD/non-rotational',
            $types,
            true
        )
    ) {
        $primary =
            'SSD/non-rotational';

    } elseif (
        in_array(
            'HDD/rotational',
            $types,
            true
        )
    ) {
        $primary =
            'HDD/rotational';
    }

    return [
        'devices' =>
            $devices,

        'primary_type' =>
            $primary,

        'source' =>
            '/sys/block',
    ];
}


// ============================================================
// OS DISCOVERY
// ============================================================

function getOsInfo(): array
{
    $result = [

        'os' =>
            null,

        'kernel' =>
            php_uname('r') ?: null,

        'hostname' =>
            php_uname('n') ?: null,

        'architecture' =>
            php_uname('m') ?: null,

        'cloudlinux' =>
            'unknown',

        'cloudlinux_release' =>
            null,

        'indicators' =>
            [],
    ];

    /*
     * /etc/os-release
     */
    if (is_readable('/etc/os-release')) {

        $lines =
            @file(
                '/etc/os-release',
                FILE_IGNORE_NEW_LINES |
                FILE_SKIP_EMPTY_LINES
            ) ?: [];

        $values = [];

        foreach ($lines as $line) {

            if (
                preg_match(
                    '/^([A-Z_]+)=(.*)$/',
                    $line,
                    $m
                )
            ) {
                $values[$m[1]] =
                    trim(
                        $m[2],
                        "\"'"
                    );
            }
        }

        $result['os'] =
            $values['PRETTY_NAME']
            ?? ($values['NAME'] ?? null);

        if (
            isset($values['ID']) &&
            stripos(
                $values['ID'],
                'cloudlinux'
            ) !== false
        ) {
            $result['cloudlinux'] = 'detected';
            $result['indicators'][] =
                '/etc/os-release';
        }
    }

    /*
     * CloudLinux release file.
     */
    foreach (
        [
            '/etc/cloudlinux-release',
            '/etc/cloudlinux-release-server'
        ] as $file
    ) {

        if (is_readable($file)) {

            $release =
                readFirstLine($file);

            if ($release !== null) {

                $result['cloudlinux'] =
                    'detected';

                $result['cloudlinux_release'] =
                    $release;

                $result['indicators'][] =
                    $file;
            }
        }
    }

    /*
     * CloudLinux-specific files/directories.
     */
    $paths = [

        '/usr/share/lve',
        '/usr/sbin/lveinfo',
        '/usr/bin/lveinfo',
        '/usr/sbin/lveps',
        '/usr/bin/lveps',
        '/proc/lve',
    ];

    foreach ($paths as $path) {

        if (file_exists($path)) {

            $result['cloudlinux'] =
                'detected';

            $result['indicators'][] =
                $path;
        }
    }

    /*
     * If nothing positively identifies CloudLinux,
     * don't say "NO" merely because PHP cannot see it.
     */
    if (
        $result['cloudlinux'] !== 'detected'
    ) {
        $result['cloudlinux'] =
            'not_confirmed';
    }

    return $result;
}


// ============================================================
// CGROUP DISCOVERY
// ============================================================

function getCgroupInfo(): array
{
    $result = [

        'available' =>
            false,

        'version' =>
            null,

        'cpu_limit_cpus' =>
            null,

        'memory_limit_bytes' =>
            null,

        'pids_limit' =>
            null,

        'source' =>
            [],
    ];

    /*
     * cgroup v2
     */
    if (
        is_readable(
            '/sys/fs/cgroup/cgroup.controllers'
        )
    ) {

        $result['available'] =
            true;

        $result['version'] =
            2;

        $result['source'][] =
            '/sys/fs/cgroup';

        $cpuMax =
            readFirstLine(
                '/sys/fs/cgroup/cpu.max'
            );

        if (
            $cpuMax &&
            preg_match(
                '/^(\d+)\s+(\d+)$/',
                $cpuMax,
                $m
            )
        ) {

            if (
                (int)$m[1] > 0 &&
                (int)$m[2] > 0
            ) {

                $result['cpu_limit_cpus'] =
                    round(
                        (int)$m[1] /
                        (int)$m[2],
                        3
                    );
            }
        }

        $memoryMax =
            readFirstLine(
                '/sys/fs/cgroup/memory.max'
            );

        if (
            $memoryMax !== null &&
            $memoryMax !== 'max' &&
            is_numeric($memoryMax)
        ) {

            $result['memory_limit_bytes'] =
                (int)$memoryMax;
        }

        $pidsMax =
            readFirstLine(
                '/sys/fs/cgroup/pids.max'
            );

        if (
            $pidsMax !== null &&
            $pidsMax !== 'max' &&
            is_numeric($pidsMax)
        ) {

            $result['pids_limit'] =
                (int)$pidsMax;
        }

    /*
     * cgroup v1
     */
    } elseif (
        is_readable(
            '/sys/fs/cgroup/cpu/cpu.cfs_quota_us'
        )
    ) {

        $result['available'] =
            true;

        $result['version'] =
            1;

        $result['source'][] =
            '/sys/fs/cgroup';

        $quota =
            readFirstLine(
                '/sys/fs/cgroup/cpu/cpu.cfs_quota_us'
            );

        $period =
            readFirstLine(
                '/sys/fs/cgroup/cpu/cpu.cfs_period_us'
            );

        if (
            $quota !== null &&
            $period !== null &&
            (int)$quota > 0 &&
            (int)$period > 0
        ) {

            $result['cpu_limit_cpus'] =
                round(
                    (int)$quota /
                    (int)$period,
                    3
                );
        }

        $memory =
            readFirstLine(
                '/sys/fs/cgroup/memory/memory.limit_in_bytes'
            );

        if (
            $memory !== null &&
            is_numeric($memory)
        ) {

            $result['memory_limit_bytes'] =
                (int)$memory;
        }

        $pids =
            readFirstLine(
                '/sys/fs/cgroup/pids/pids.max'
            );

        if (
            $pids !== null &&
            is_numeric($pids)
        ) {

            $result['pids_limit'] =
                (int)$pids;
        }
    }

    return $result;
}


// ============================================================
// CLOUDLINUX / LVE DISCOVERY
// ============================================================

function findLveTool(string $name): ?string
{
    $paths = [

        '/usr/sbin/' . $name,
        '/usr/bin/' . $name,
        '/usr/local/sbin/' . $name,
        '/usr/local/bin/' . $name,
    ];

    foreach ($paths as $path) {

        if (
            is_file($path) &&
            is_executable($path)
        ) {
            return $path;
        }
    }

    return commandPath($name);
}


function parseLveInfoOutput(
    string $output
): array {
    $result = [
        'raw' => $output,
        'limits' => [],
        'parsed' => false,
    ];

    /*
     * lveinfo output varies between CloudLinux releases.
     * Therefore use conservative pattern matching rather
     * than assuming one exact output format.
     */

    $patterns = [

        'cpu' => [
            '/(?:CPU|CPUF|CPU limit)\s*[:=]\s*([0-9.]+)/i',
        ],

        'pmem' => [
            '/(?:PMEM|memory|mem)\s*[:=]\s*([0-9.]+)\s*(MB|GB|M|G)?/i',
        ],

        'ep' => [
            '/(?:EP|Entry Processes)\s*[:=]\s*(\d+)/i',
        ],

        'nproc' => [
            '/(?:NPROC|processes)\s*[:=]\s*(\d+)/i',
        ],

        'io' => [
            '/(?:IO|I\/O)\s*[:=]\s*([0-9.]+)\s*(MB\/s|KB\/s|M|K)?/i',
        ],

        'iops' => [
            '/(?:IOPS)\s*[:=]\s*(\d+)/i',
        ],
    ];

    foreach (
        $patterns as $key => $variants
    ) {

        foreach ($variants as $pattern) {

            if (
                preg_match(
                    $pattern,
                    $output,
                    $m
                )
            ) {

                $value =
                    $m[1];

                $unit =
                    strtolower(
                        $m[2] ?? ''
                    );

                if ($key === 'pmem') {

                    $number =
                        (float)$value;

                    if (
                        $unit === 'gb' ||
                        $unit === 'g'
                    ) {
                        $number *= 1024;
                    }

                    $result['limits'][$key] =
                        $number . ' MB';

                } else {

                    $result['limits'][$key] =
                        is_numeric($value)
                            ? (
                                strpos(
                                    $value,
                                    '.'
                                ) !== false
                                    ? (float)$value
                                    : (int)$value
                            )
                            : $value;
                }

                $result['parsed'] =
                    true;

                break;
            }
        }
    }

    return $result;
}


function getLveInfo(): array
{
    $result = [

        'detected' =>
            false,

        'tool' =>
            null,

        'accessible' =>
            false,

        'limits' =>
            [],

        'raw' =>
            null,

        'source' =>
            [],
    ];

    $tool =
        findLveTool('lveinfo');

    if ($tool !== null) {

        $result['detected'] =
            true;

        $result['tool'] =
            $tool;

        $result['source'][] =
            $tool;

        /*
         * Try a few common invocations. We don't assume
         * that any particular invocation exists on every
         * CloudLinux release.
         */
        $commands = [

            escapeshellarg($tool),

            escapeshellarg($tool) .
            ' --help',

            escapeshellarg($tool) .
            ' --version',
        ];

        foreach ($commands as $command) {

            $output =
                runCommand(
                    $command,
                    2
                );

            if ($output !== null) {

                $result['accessible'] =
                    true;

                $result['raw'] =
                    $output;

                $parsed =
                    parseLveInfoOutput(
                        $output
                    );

                if (
                    $parsed['parsed']
                ) {

                    $result['limits'] =
                        $parsed['limits'];
                }

                break;
            }
        }
    }

    /*
     * /proc/lve is itself a useful CloudLinux indicator.
     */
    if (file_exists('/proc/lve')) {

        $result['detected'] =
            true;

        $result['source'][] =
            '/proc/lve';
    }

    return $result;
}


// ============================================================
// OPENLITESPEED DISCOVERY
// ============================================================

function getOpenLiteSpeedInfo(): array
{
    $result = [

        'detected' =>
            false,

        'version' =>
            null,

        'source' =>
            [],
    ];

    $commands = [

        'openlitespeed -v',

        'lshttpd -v',

        '/usr/local/lsws/bin/openlitespeed -v',

        '/usr/local/lsws/bin/lshttpd -v',
    ];

    foreach ($commands as $command) {

        $output =
            runCommand(
                $command,
                2
            );

        if ($output !== null) {

            $result['detected'] =
                true;

            $result['version'] =
                $output;

            $result['source'][] =
                $command;

            break;
        }
    }

    /*
     * Installation directory is also an indicator.
     */
    if (
        is_dir('/usr/local/lsws')
    ) {

        $result['detected'] =
            true;

        $result['source'][] =
            '/usr/local/lsws';
    }

    return $result;
}


// ============================================================
// MARIADB DISCOVERY
// ============================================================

function getMariaDbInfo(
    ?PDO $pdo
): array {

    $result = [

        'available' =>
            false,

        'version' =>
            null,

        'variables' =>
            [],

        'source' =>
            [],
    ];

    if (!$pdo) {
        return $result;
    }

    $result['available'] =
        true;

    $result['source'][] =
        'PDO';

    try {

        $result['version'] =
            (string)$pdo
                ->query(
                    'SELECT VERSION()'
                )
                ->fetchColumn();

    } catch (Throwable $e) {

        return $result;
    }

    /*
     * Variables which are useful to the configuration
     * recommendation engine.
     */
    $wanted = [

        'version',

        'innodb_buffer_pool_size',

        'innodb_buffer_pool_instances',

        'max_connections',

        'innodb_log_file_size',

        'innodb_log_files_in_group',

        'innodb_redo_log_capacity',

        'innodb_io_capacity',

        'innodb_io_capacity_max',

        'table_open_cache',

        'table_definition_cache',

        'thread_cache_size',

        'tmp_table_size',

        'max_heap_table_size',

        'innodb_flush_log_at_trx_commit',

        'innodb_flush_method',

        'max_allowed_packet',

        'query_cache_type',

        'query_cache_size',

        'performance_schema',
    ];

    foreach ($wanted as $name) {

        try {

            $stmt =
                $pdo->prepare(
                    'SHOW VARIABLES LIKE ?'
                );

            $stmt->execute([
                $name
            ]);

            $row =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if ($row) {

                $result['variables'][$name] =
                    $row['Value'];
            }

        } catch (Throwable $e) {

            /*
             * MariaDB versions differ in which variables
             * are available.
             */
        }
    }

    return $result;
}


// ============================================================
// SERVER INFORMATION
// ============================================================

function getServerInfo(
    ?PDO $pdo
): array {

    $cpu =
        getCpuInfo();

    $memory =
        getMemoryInfo();

    $storage =
        getStorageInfo();

    $os =
        getOsInfo();

    $cgroup =
        getCgroupInfo();

    $lve =
        getLveInfo();

    $ols =
        getOpenLiteSpeedInfo();

    $maria =
        getMariaDbInfo($pdo);

    /*
     * If lveinfo/proc/lve found CloudLinux but the OS release
     * did not, upgrade the overall detection state.
     */
    if (
        ($lve['detected'] ?? false) &&
        ($os['cloudlinux'] ?? '') !== 'detected'
    ) {
        $os['cloudlinux'] =
            'detected';

        $os['indicators'][] =
            'LVE subsystem';
    }

    return [

        'type' =>
            'server_info',

        'schema_version' =>
            2,

        'collected_at' =>
            date('Y-m-d H:i:s'),

        'cpu' =>
            $cpu,

        'memory' =>
            $memory,

        'storage' =>
            $storage,

        'os' =>
            $os,

        'cgroup' =>
            $cgroup,

        'cloudlinux' =>
            [
                'status' =>
                    $os['cloudlinux'],

                'lve' =>
                    $lve,
            ],

        'openlitespeed' =>
            $ols,

        'php' =>
            [
                'version' =>
                    PHP_VERSION,

                'sapi' =>
                    PHP_SAPI,

                'memory_limit' =>
                    ini_get('memory_limit'),

                'upload_max_filesize' =>
                    ini_get(
                        'upload_max_filesize'
                    ),

                'max_execution_time' =>
                    ini_get(
                        'max_execution_time'
                    ),
            ],

        'mariadb' =>
            $maria,
    ];
}


// ============================================================
// BEGIN PROBE
// ============================================================

$time =
    date('Y-m-d H:i:s');

$phpStart =
    microtime(true);


// ============================================================
// DATABASE TEST
// ============================================================

$dbQueryMs  = null;
$dbConnectMs = null;
$dbError    = null;
$pdo        = null;

try {

    $t =
        microtime(true);

    include __DIR__ .
        '/probe_config.php';

    $dbConnectMs =
        ms($t);

    if (
        isset($pdo) &&
        $pdo instanceof PDO
    ) {

        $t =
            microtime(true);

        $pdo
            ->query(
                'SELECT 1'
            )
            ->fetch();

        $dbQueryMs =
            ms($t);
    }

} catch (Throwable $e) {

    $dbError =
        $e->getMessage();
}


// ============================================================
// SERVER DISCOVERY
// ============================================================

$serverInfo =
    getServerInfo(
        $pdo instanceof PDO
            ? $pdo
            : null
    );


// ============================================================
// FILESYSTEM TESTS
// ============================================================

$tmpDir =
    sys_get_temp_dir();


// ------------------------------------------------------------
// Buffered append
// ------------------------------------------------------------

$t =
    microtime(true);

@file_put_contents(
    $tmpDir . '/probe_fs.tmp',
    $time . PHP_EOL,
    FILE_APPEND
);

$fsWriteMs =
    ms($t);


// ------------------------------------------------------------
// Sync write
// ------------------------------------------------------------

$t =
    microtime(true);

$fp =
    @fopen(
        $tmpDir . '/probe_sync.tmp',
        'ab'
    );

if ($fp) {

    @fwrite(
        $fp,
        "sync\n"
    );

    @fflush($fp);

    if (function_exists('fsync')) {
        @fsync($fp);
    }

    @fclose($fp);
}

$fsSyncMs =
    ms($t);


// ------------------------------------------------------------
// Create file
// ------------------------------------------------------------

$t =
    microtime(true);

$createFile =
    $tmpDir .
    '/probe_create_' .
    uniqid('', true);

@touch($createFile);

$createMs =
    ms($t);


// ------------------------------------------------------------
// Stat file
// ------------------------------------------------------------

$t =
    microtime(true);

clearstatcache(
    true,
    $createFile
);

@stat($createFile);

$statMs =
    ms($t);


// ------------------------------------------------------------
// Delete file
// ------------------------------------------------------------

$t =
    microtime(true);

@unlink($createFile);

$deleteMs =
    ms($t);


// ------------------------------------------------------------
// Directory scan
// ------------------------------------------------------------

$t =
    microtime(true);

@scandir($tmpDir);

$scandirMs =
    ms($t);


// ------------------------------------------------------------
// Inode metadata
// ------------------------------------------------------------

$inodeTimes = [];

for ($i = 0; $i < 5; $i++) {

    $f =
        $tmpDir .
        '/inode_probe_' .
        uniqid();

    $t =
        microtime(true);

    @touch($f);
    @unlink($f);

    $inodeTimes[] =
        ms($t);
}

$inodeMs =
    avg($inodeTimes);


// ------------------------------------------------------------
// Include latency
// ------------------------------------------------------------

$includeFile =
    $tmpDir .
    '/probe_include.php';

@file_put_contents(
    $includeFile,
    '<?php return true;'
);

$t =
    microtime(true);

@include $includeFile;

$includeMs =
    ms($t);

@unlink($includeFile);


// ------------------------------------------------------------
// Session write latency
// ------------------------------------------------------------

$sessionMs =
    null;

if (
    function_exists(
        'session_write_close'
    )
) {

    if (
        session_status() !==
        PHP_SESSION_ACTIVE
    ) {
        @session_start();
    }

    if (
        session_status() ===
        PHP_SESSION_ACTIVE
    ) {

        $_SESSION['probe_time'] =
            microtime(true);

        $t =
            microtime(true);

        @session_write_close();

        $sessionMs =
            ms($t);
    }
}


// ============================================================
// MEMORY / LOAD
// ============================================================

$memoryMb =
    round(
        memory_get_peak_usage(true)
        / 1024 / 1024,
        2
    );

$load =
    function_exists(
        'sys_getloadavg'
    )
        ? sys_getloadavg()
        : [null, null, null];

$load1 =
    $load[0] ?? null;

$load5 =
    $load[1] ?? null;

$load15 =
    $load[2] ?? null;

$cpuCount =
    $serverInfo['cpu']['logical_cpus']
    ?? null;

$loadRatio =
    (
        $cpuCount &&
        $load1 !== null
    )
        ? round(
            $load1 /
            $cpuCount,
            3
        )
        : null;


// ============================================================
// PHP TOTAL
// ============================================================

$phpMs =
    ms($phpStart);


// ============================================================
// BOTTLENECK HINT
// ============================================================

$bottleneck =
    'NORMAL';

if (
    $fsSyncMs > 250 ||
    ($inodeMs ?? 0) > 100 ||
    $createMs > 100 ||
    $deleteMs > 100
) {

    $bottleneck =
        'STORAGE';

} elseif (
    $dbQueryMs !== null &&
    $dbQueryMs > 100
) {

    $bottleneck =
        'DATABASE';

} elseif (
    $phpMs > 1000
) {

    $bottleneck =
        'PHP';
}


// ============================================================
// PERFORMANCE RECORD
// ============================================================

$record = [

    'type' =>
        'sample',

    'ts' =>
        time(),

    'time' =>
        $time,

    'php' =>
        $phpMs,

    'fs_write' =>
        $fsWriteMs,

    'fs_sync' =>
        $fsSyncMs,

    'inode' =>
        $inodeMs,

    'file_create' =>
        $createMs,

    'file_delete' =>
        $deleteMs,

    'file_stat' =>
        $statMs,

    'dir_scan' =>
        $scandirMs,

    'include' =>
        $includeMs,

    'session' =>
        $sessionMs,

    'db_connect' =>
        $dbConnectMs,

    'db_query' =>
        $dbQueryMs,

    'db_error' =>
        $dbError,

    'memory_mb' =>
        $memoryMb,

    'load1' =>
        $load1,

    'load5' =>
        $load5,

    'load15' =>
        $load15,

    'cpu' =>
        $cpuCount,

    'load_ratio' =>
        $loadRatio,

    'bottleneck' =>
        $bottleneck,
];


// ============================================================
// SERVER INFO FILE
// ============================================================

/*
 * Always refresh server_info.json.
 *
 * Hardware/configuration can change without the metrics
 * history changing. This file therefore represents the
 * latest discovery snapshot.
 */

@file_put_contents(
    $serverInfoFile,
    json_encode(
        $serverInfo,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL,
    LOCK_EX
);


// ============================================================
// NDJSON
// ============================================================

/*
 * A new NDJSON file begins with server metadata.
 *
 * Existing files are NOT rewritten.
 */
$needsServerInfo =
    !file_exists($logfile) ||
    filesize($logfile) === 0;

if ($needsServerInfo) {

    @file_put_contents(
        $logfile,
        json_encode(
            $serverInfo,
            JSON_UNESCAPED_SLASHES
        ) . PHP_EOL,
        FILE_APPEND |
        LOCK_EX
    );
}

@file_put_contents(
    $logfile,
    json_encode(
        $record,
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL,
    FILE_APPEND |
    LOCK_EX
);


// ============================================================
// OUTPUT
// ============================================================

echo sprintf(
    "OK %s PHP=%.1fms FS=%.1fms SYNC=%.1fms DB=%.1fms BOTTLENECK=%s\n",
    $time,
    $phpMs,
    $fsWriteMs,
    $fsSyncMs,
    $dbQueryMs ?? 0,
    $bottleneck
);
