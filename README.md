Server Health Incident Tracker
A lightweight PHP health-monitoring and diagnostic toolkit for shared

web servers running OpenLiteSpeed, CloudLinux, MariaDB, and

PHP.
The project is designed to answer a more useful question than "is the

server up?"
When performance degrades, what subsystem appears to be responsible,

how severe is it, and is the problem isolated or sustained?
The probe collects low-level execution, filesystem, database, CPU,

memory, server, and hosting-environment information. A report generator

analyzes the historical data and produces a human-readable health report

with incident analysis and configuration recommendations.
Features
Performance monitoring
probe.php records measurements including:
·	PHP execution latency
·	Filesystem write latency
·	Filesystem sync latency
·	Inode/stat operations
·	File creation/deletion operations
·	Directory scanning
·	Include/require operations
·	Session operations
·	MariaDB connection latency
·	MariaDB query latency
·	Database errors
·	Memory usage
·	1/5/15-minute load averages
·	CPU information
·	Load ratio relative to CPUs visible to PHP
·	A calculated bottleneck classification
Measurements are written as NDJSON, making the data append-only and

easy to process with standard Unix tools.
Hardware discovery
The probe attempts to discover the underlying server specifications

rather than relying solely on what PHP's execution environment reports.
Collected information can include:
·	CPU model
·	Logical CPU count
·	Physical cores
·	CPU sockets
·	Threads per core
·	CPU architecture
·	Total/available memory
·	Swap
·	Filesystem capacity
·	Filesystem type
·	Operating-system information
The discovery snapshot is written to:
server_info.json

This is particularly useful on shared hosting, where the PHP process may

see only a restricted execution environment.
CloudLinux / LVE detection
The probe attempts to identify CloudLinux and, where permitted, query

LVE information.
It deliberately distinguishes:
·	physical/bare-metal information
·	PHP-visible information
·	cgroup limits
·	CloudLinux/LVE limits
The absence of cgroup information is not interpreted as proof that

CloudLinux is absent.
Where LVE information is accessible, the report can incorporate limits

such as:
·	CPU
·	PMEM
·	EP
·	NPROC
·	IO
·	IOPS
OpenLiteSpeed detection
The probe attempts to identify OpenLiteSpeed and record useful server

information available to the PHP environment.
The report uses this information when discussing:
·	PHP/application process pressure
·	concurrent request pressure
·	external application queues
·	CloudLinux EP/NPROC considerations
MariaDB discovery
The probe attempts to query MariaDB configuration and records relevant

variables where accessible.
The report can use the detected hardware and MariaDB configuration to

produce recommendations concerning items such as:
·	innodb_buffer_pool_size
·	max_connections
·	innodb_io_capacity
·	innodb_io_capacity_max
·	temporary table sizing
·	innodb_flush_log_at_trx_commit
·	innodb_flush_method
·	query cache configuration
Recommendations are intended as starting points for a shared server,

not universal tuning values.
Incident analysis
The report generator does more than list threshold violations.
A single abnormal sample is treated as an:
ISOLATED SPIKE

rather than automatically being called an incident.
Repeated abnormal samples are grouped into episodes. The current

grouping permits a short gap between abnormal samples so that one

normal/missing probe does not unnecessarily split an ongoing problem.
Episodes are classified as:
·	ISOLATED SPIKE
·	REPEATED INCIDENT
·	SUSTAINED INCIDENT
For each episode, the report can show:
·	start and end time
·	duration
·	number of samples
·	affected subsystems
·	severity from 0--100
·	average metric values
·	P95 values
·	maximum values
·	diagnostic assessment
·	suggested investigative action
Evidence-based diagnosis
The incident engine compares related measurements rather than assuming

that the first threshold exceeded is the root cause.
For example:
High filesystem latency
+ high PHP latency
+ normal database query latency

is interpreted as evidence favoring a storage/I/O problem rather than a

MariaDB problem.
Likewise:
Normal filesystem latency
+ high database query latency
+ high PHP latency

points toward database workload, locking, or query behavior.
The report intentionally avoids claiming a root cause that the collected

data cannot establish.
Data files
The normal operating directory contains:
probe.php
report_generator.php
probe_metrics.ndjson
server_info.json
report.html

If an external HTTP probe is used, an additional file may be present:
external_metrics.ndjson

probe_metrics.ndjson
Append-only historical probe data.
The first record may contain a server_info record. Subsequent records

contain performance samples.
Example fields include:
time
ts
php
fs_write
fs_sync
inode
file_create
file_delete
file_stat
dir_scan
include
session
db_connect
db_query
db_error
memory
load1
load5
load15
cpu
load_ratio
bottleneck

server_info.json
Latest hardware and server-environment discovery snapshot.
This file is refreshed by probe.php so hardware/configuration changes

can be detected without rewriting the historical metrics file.
report.html
Generated by report_generator.php.
The report contains the health analysis, statistics, incident timeline,

environment information, CloudLinux/OpenLiteSpeed recommendations, and

MariaDB recommendations.
External HTTP probe
The project can also use an external probe to measure the server from

outside the web server's local PHP execution environment.
A typical external probe records:
dns
connect
tls
ttfb
total

This makes it possible to compare internal measurements against

externally observed HTTP performance.
For example:
High FS_SYNC
High PHP
High external TTFB
Normal DB_QUERY

is stronger evidence of a server/storage-side performance problem than

any one measurement alone.
An external probe can also pass an incident identifier so measurements

can be correlated.
Installation
The project requires:
·	PHP 8.x
·	CLI or web PHP execution as appropriate
·	access to the directory where the scripts and data files reside
·	file_put_contents()
·	standard PHP filesystem functions
Some discovery features depend on access to Linux facilities such as:
/proc/cpuinfo
/proc/meminfo
/sys/fs/cgroup

and on commands being available to the PHP execution environment.
The scripts are designed to degrade gracefully when those facilities are

unavailable.
Basic setup
Place the files in a directory accessible to the PHP runtime:
/health/
    probe.php
    report_generator.php

Run the probe periodically:
php /path/to/health/probe.php

Generate the report:
php /path/to/health/report_generator.php

The report generator writes:
report.html

Cron
A common configuration is to run the probe every five minutes:
*/5 * * * * /usr/local/bin/php /path/to/health/probe.php >/dev/null 2>&1

Generate the report periodically, for example every 15 minutes:
*/15 * * * * /usr/local/bin/php /path/to/health/report_generator.php >/dev/null 2>&1

Adjust the PHP path and installation directory for the target system.
A five-minute probe interval is also the basis for the incident engine's

default short-gap handling.
Thresholds
The report currently uses these principal thresholds:
Metric                Threshold

Filesystem write         250 ms

Filesystem sync          100 ms

Inode operation           25 ms

File operations          100 ms

Session operation        100 ms

PHP execution            500 ms

DB connection             50 ms

DB query                  50 ms

Load ratio                  1.0
These are diagnostic thresholds, not claims that every server should

consider the values unacceptable.
They are intended to identify meaningful deviations in a shared-hosting

environment.
The thresholds are defined in report_generator.php and can be adjusted

for a particular workload.
Severity
Episode severity is based on:
·	magnitude of the abnormal measurement
·	persistence
·	number of affected signal categories
Severity is capped at:
100

The goal is to rank episodes for investigation rather than provide a

formal SLA score.
CloudLinux recommendations
The report deliberately avoids inventing LVE limits when they cannot be

observed.
For example, if CloudLinux is known to be present but PHP cannot access

the relevant LVE tooling, the report indicates that the limits must be

obtained from:
·	CloudLinux/LVE Manager
·	the hosting control panel
·	the hosting provider
This distinction matters because:
cgroup limit

and:
CloudLinux LVE limit

are not necessarily the same thing.
Likewise, the number of CPUs visible to PHP should not automatically be

treated as the physical host CPU count.
MariaDB recommendations
The MariaDB section uses detected hardware as context.
Because this is intended for a shared web server, buffer-pool

guidance is intentionally more conservative than recommendations for a

dedicated database server.
The report also considers observed filesystem latency before

recommending MariaDB I/O changes.
For example, if filesystem latency is already severely elevated,

increasing:
innodb_io_capacity
innodb_io_capacity_max

may be inappropriate until the underlying storage/LVE situation is

understood.
The report is therefore intended to support investigation rather than

blindly apply tuning values.
Security and deployment considerations
Protect the data files
probe_metrics.ndjson and server_info.json can contain infrastructure

information that should not normally be exposed publicly.
If the scripts are installed beneath a web-accessible directory, deny

direct HTTP access to:
probe_metrics.ndjson
server_info.json

and any other diagnostic data files.
The generated report.html should likewise be protected if it contains

sensitive server information.
Probe endpoint
If probe.php is exposed as a web endpoint, consider restricting access

or placing it behind an authentication mechanism.
The project is primarily intended to run from cron or another controlled

execution mechanism.
Command execution
The probe uses Linux command discovery/execution for some hardware and

environment detection. On restricted shared hosting, those commands may

be unavailable. The code is designed to treat unavailable information as

unavailable rather than fabricate values.
Limitations
This project cannot determine everything about a shared server from PHP.
In particular:
·	PHP may not be able to see the physical host's complete CPU

topology.
·	PHP may see a virtualized or restricted CPU environment.
·	CloudLinux LVE limits may be hidden.
·	cgroup information may be unavailable.
·	Host-side storage queue depth and physical NVMe/SATA behavior may

not be directly observable.
·	Other tenants on a shared server are outside the probe's visibility.
·	A filesystem latency spike does not by itself establish the physical

cause.
·	High load does not automatically mean CPU saturation.
·	High PHP latency does not automatically mean PHP itself is the root

cause.
·	A database latency measurement is only as representative as the test

query.
·	Recommendations should be validated against actual workload and

provider constraints.
The report intentionally distinguishes observed evidence from

possible causes.
Design philosophy
The project follows several principles:
1.	Measure before tuning.
2.	Separate physical hardware from execution-environment limits.
3.	Do not mistake one spike for an incident.
4.	Correlate multiple metrics before assigning a likely bottleneck.
5.	Treat CloudLinux limits as distinct from cgroup visibility.
6.	Treat shared-server MariaDB tuning differently from dedicated

database-server tuning.
7.	Prefer evidence-based recommendations over generic optimization

advice.
8.	Preserve raw historical measurements so the analysis can be

improved later.
Example workflow
A typical workflow is:
                    ┌─────────────────┐
                    │    probe.php    │
                    └────────┬────────┘
                             │
                             ▼
                    probe_metrics.ndjson
                             │
              ┌──────────────┴──────────────┐
              │                             │
              ▼                             ▼
      server_info.json              Historical samples
              │                             │
              └──────────────┬──────────────┘
                             ▼
                  ┌────────────────────┐
                  │ report_generator.php│
                  └─────────┬──────────┘
                            │
             ┌──────────────┼──────────────┐
             ▼              ▼              ▼
          Health         Incidents     Configuration
         statistics      & anomalies   recommendations
             │              │              │
             └──────────────┼──────────────┘
                            ▼
                       report.html

License
Add the project's chosen license here before publishing the repository.
If this project is intended to be redistributed, an OSI-approved license

such as MIT, BSD-2-Clause, or GPL-3.0 can be selected according to the

project's intended reuse requirements.
Status
This project is intended as a practical diagnostic and monitoring tool

for Linux-based shared web servers.
It should be considered observational/diagnostic software, not a

substitute for host-level monitoring, CloudLinux/LVE Manager, MariaDB

monitoring, OpenLiteSpeed monitoring, or infrastructure-provider

telemetry.
