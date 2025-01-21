<?php

declare(strict_types=1);

use App\Backends\Emby\EmbyClient;
use App\Backends\Jellyfin\JellyfinClient;
use App\Backends\Plex\PlexClient;
use App\Commands\Events\DispatchCommand;
use App\Commands\State\BackupCommand;
use App\Commands\State\ExportCommand;
use App\Commands\State\ImportCommand;
use App\Commands\State\SyncCommand;
use App\Commands\System\IndexCommand;
use App\Commands\System\PruneCommand;
use App\Libs\Mappers\Import\MemoryMapper;
use Cron\CronExpression;
use Monolog\Level;

if (!defined('PF')) {
    define('PF', 'WSN_');
}

return (function () {
    $inContainer = inContainer();
    $config = [
        'name' => 'ws-next',
        'version' => '$(version_via_ci)',
        'tz' => env(PF . 'TZ', env('TZ', 'UTC')),
        'path' => fixPath(env('DATA_PATH', fn () => $inContainer ? '/config' : __DIR__ . '/../var')),
        'logs' => [
            'context' => (bool)env(PF . 'LOGS_CONTEXT', false),
            'prune' => [
                'after' => env(PF . 'LOGS_PRUNE_AFTER', '-3 DAYS'),
            ],
        ],
        'api' => [
            'prefix' => '/v1/api',
            'key' => env(PF . 'API_KEY', null),
            'secure' => (bool)env(PF . 'SECURE_API_ENDPOINTS', false),
            'auto' => (bool)env(PF . 'API_AUTO', false),
            'pattern_match' => [
                'backend' => '[a-zA-Z0-9_-]+',
            ],
            'logInternal' => (bool)env(PF . 'API_LOG_INTERNAL', false),
            'response' => [
                'encode' => JSON_INVALID_UTF8_IGNORE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Application-Version' => fn () => getAppVersion(),
                    'Access-Control-Allow-Origin' => '*',
                ],
            ],
        ],
        'webui' => [
            'enabled' => (bool)env('WEBUI_ENABLED', env(PF . 'WEBUI_ENABLED', true)),
            'path' => fixPath(env(PF . 'WEBUI_PATH', __DIR__ . '/../public/exported')),
        ],
        'database' => [
            'version' => 'v01',
        ],
        'library' => [
            // -- this is used to segment backends requests into pages.
            'segment' => (int)env(PF . 'LIBRARY_SEGMENT', 1_000),
        ],
        'export' => [
            // -- Trigger full export mode if changes exceed X number.
            'threshold' => (int)env(PF . 'EXPORT_THRESHOLD', 1_000),
            // -- Extra margin for marking item not found for backend in export mode. Default 3 days.
            'not_found' => (int)env(PF . 'EXPORT_NOT_FOUND', 259_200),
        ],
        'episodes' => [
            'disable' => [
                'guid' => (bool)env(PF . 'EPISODES_DISABLE_GUID', true),
            ]
        ],
        'ignore' => [],
        'trust' => [
            'proxy' => (bool)env(PF . 'TRUST_PROXY', false),
            'header' => (string)env(PF . 'TRUST_HEADER', 'X-Forwarded-For'),
        ],
        'sync' => [
            'progress' => (bool)env(PF . 'SYNC_PROGRESS', false),
        ],
        'push' => [
            'enabled' => (bool)env(PF . 'PUSH_ENABLED', false),
        ],
    ];

    $config['guid'] = [
        'version' => '0.0',
        'file' => fixPath(env(PF . 'GUID_FILE', ag($config, 'path') . '/config/guid.yaml')),
    ];

    $config['backends_file'] = fixPath(env(PF . 'BACKENDS_FILE', ag($config, 'path') . '/config/servers.yaml'));
    $config['mapper_file'] = fixPath(env(PF . 'MAPPER_FILE', ag($config, 'path') . '/config/mapper.yaml'));

    date_default_timezone_set(ag($config, 'tz', 'UTC'));
    $logDateFormat = makeDate()->format('Ymd');

    $config['tmpDir'] = fixPath(env('TMP_DIR', ag($config, 'path')));

    $dbFile = ag($config, 'path') . '/db/watchstate_' . ag($config, 'database.version') . '.db';

    $config['api']['logfile'] = ag($config, 'tmpDir') . '/logs/access.' . $logDateFormat . '.log';

    $config['database'] += [
        'file' => $dbFile,
        'dsn' => 'sqlite:' . $dbFile,
        'username' => null,
        'password' => null,
        'options' => [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
        'exec' => [
            'PRAGMA journal_mode=MEMORY',
            'PRAGMA SYNCHRONOUS=OFF'
        ],
    ];

    $config['webhook'] = [
        'logfile' => ag($config, 'tmpDir') . '/logs/webhook.' . $logDateFormat . '.log',
        'dumpRequest' => (bool)env(PF . 'WEBHOOK_DUMP_REQUEST', false),
        'tokenLength' => (int)env(PF . 'WEBHOOK_TOKEN_LENGTH', 16),
        'file_format' => (string)env(PF . 'WEBHOOK_LOG_FILE_FORMAT', 'webhook.{backend}.{event}.{id}.json'),
    ];

    $config['mapper'] = [
        'import' => [
            'type' => MemoryMapper::class,
            'opts' => [],
        ],
    ];

    $config['http'] = [
        'default' => [
            'options' => [
                'headers' => [
                    'User-Agent' => ag($config, 'name') . '/' . getAppVersion(),
                ],
                'timeout' => 300.0,
                'extra' => [
                    'curl' => [
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    ],
                ],
            ],
        ],
    ];

    $config['debug'] = [
        'enabled' => (bool)env(PF . 'DEBUG', false),
        'profiler' => [
            'options' => [
                'save.handler' => 'file',
                'save.handler.file' => [
                    'filename' => ag($config, 'tmpDir') . '/profiler/run.' . makeDate()->format('Ymd_His') . '.json'
                ],
            ],
        ],
    ];

    $config['cache'] = [
        'prefix' => env(PF . 'CACHE_PREFIX', null),
        'url' => env(PF . 'CACHE_URL', 'redis://127.0.0.1:6379'),
        'path' => env(PF . 'CACHE_PATH', fn () => ag($config, 'tmpDir') . '/cache'),
    ];

    $config['logger'] = [
        'file' => [
            'type' => 'stream',
            'enabled' => (bool)env(PF . 'LOGGER_FILE_ENABLE', true),
            'level' => env(PF . 'LOGGER_FILE_LEVEL', Level::Error),
            'filename' => ag($config, 'tmpDir') . '/logs/app.' . $logDateFormat . '.log',
        ],
        'stderr' => [
            'type' => 'stream',
            'enabled' => 'cli' !== PHP_SAPI,
            'level' => Level::Warning,
            'filename' => 'php://stderr',
        ],
        'console' => [
            'type' => 'console',
            'enabled' => 'cli' === PHP_SAPI,
            // -- controllable by -vvv flag -v for NOTICE -vv for INFO -vvv for DEBUG.
            'level' => Level::Warning,
        ],
        'syslog' => [
            'type' => 'syslog',
            'docker' => false,
            'facility' => env(PF . 'LOGGER_SYSLOG_FACILITY', LOG_USER),
            'enabled' => (bool)env(PF . 'LOGGER_SYSLOG_ENABLED', !$inContainer),
            'level' => env(PF . 'LOGGER_SYSLOG_LEVEL', Level::Error),
            'name' => ag($config, 'name'),
        ],
    ];

    $config['supported'] = [
        strtolower(PlexClient::CLIENT_NAME) => PlexClient::class,
        strtolower(EmbyClient::CLIENT_NAME) => EmbyClient::class,
        strtolower(JellyfinClient::CLIENT_NAME) => JellyfinClient::class,
    ];

    $config['servers'] = [];
    $config['console'] = [
        'enable' => [
            'all' => (bool)env(PF . 'CONSOLE_ENABLE_ALL', false),
        ],
    ];

    $config['php'] = [
        'ini' => [
            'disable_functions' => null,
            'display_errors' => 0,
            'error_log' => $inContainer ? '/proc/self/fd/2' : 'syslog',
            'syslog.ident' => 'php-fpm',
            'memory_limit' => '265M',
            'pcre.jit' => 1,
            'opcache.enable' => 1,
            'opcache.memory_consumption' => 128,
            'opcache.interned_strings_buffer' => 8,
            'opcache.max_accelerated_files' => 10000,
            'opcache.max_wasted_percentage' => 5,
            'expose_php' => 0,
            'date.timezone' => ag($config, 'tz', 'UTC'),
            'mbstring.http_input' => ag($config, 'charset', 'UTF-8'),
            'mbstring.http_output' => ag($config, 'charset', 'UTF-8'),
            'mbstring.internal_encoding' => ag($config, 'charset', 'UTF-8'),
        ],
        'fpm' => [
            'global' => [
                'daemonize' => 'no',
                'error_log' => '/proc/self/fd/2',
                'log_limit' => '8192',
            ],
            'www' => [
                'clear_env' => 'no',
                'pm' => 'dynamic',
                'pm.max_children' => 10,
                'pm.start_servers' => 1,
                'pm.min_spare_servers' => 1,
                'pm.max_spare_servers' => 3,
                'pm.max_requests' => 1000,
                'pm.status_path' => '/status',
                'ping.path' => '/ping',
                'catch_workers_output' => 'yes',
                'decorate_workers_output' => 'no',
            ],
        ],
    ];

    $checkTaskTimer = function (string $timer, string $default): string {
        try {
            $isValid = new CronExpression($timer)->getNextRunDate()->getTimestamp() >= 0;
            return $isValid ? $timer : $default;
        } catch (Throwable) {
            return $default;
        }
    };

    $config['tasks'] = [
        'logfile' => ag($config, 'tmpDir') . '/logs/task.' . $logDateFormat . '.log',
        'list' => [
            ImportCommand::TASK_NAME => [
                'command' => ImportCommand::ROUTE,
                'name' => ImportCommand::TASK_NAME,
                'info' => 'Import data from backends.',
                'enabled' => (bool)env(PF . 'CRON_IMPORT', false),
                'timer' => $checkTaskTimer((string)env(PF . 'CRON_IMPORT_AT', '0 */1 * * *'), '0 */1 * * *'),
                'args' => env(PF . 'CRON_IMPORT_ARGS', '-v'),
            ],
            ExportCommand::TASK_NAME => [
                'command' => ExportCommand::ROUTE,
                'name' => ExportCommand::TASK_NAME,
                'info' => 'Export data to backends.',
                'enabled' => (bool)env(PF . 'CRON_EXPORT', false),
                'timer' => $checkTaskTimer((string)env(PF . 'CRON_EXPORT_AT', '30 */1 * * *'), '30 */1 * * *'),
                'args' => env(PF . 'CRON_EXPORT_ARGS', '-v'),
            ],
            SyncCommand::TASK_NAME => [
                'command' => SyncCommand::ROUTE,
                'name' => SyncCommand::TASK_NAME,
                'info' => '[Alpha stage] Sync All users play state. Read the FAQ.',
                'enabled' => (bool)env(PF . 'CRON_SYNC', false),
                'timer' => $checkTaskTimer((string)env(PF . 'CRON_SYNC_AT', '9 */3 * * *'), '9 */3 * * *'),
                'args' => env(PF . 'CRON_SYNC_ARGS', '-v'),
            ],
            BackupCommand::TASK_NAME => [
                'command' => BackupCommand::ROUTE,
                'name' => BackupCommand::TASK_NAME,
                'info' => 'Backup backends play states.',
                'enabled' => (bool)env(PF . 'CRON_BACKUP', true),
                'timer' => $checkTaskTimer((string)env(PF . 'CRON_BACKUP_AT', '0 6 */3 * *'), '0 6 */3 * *'),
                'args' => env(PF . 'CRON_BACKUP_ARGS', '-v'),
            ],
            PruneCommand::TASK_NAME => [
                'command' => PruneCommand::ROUTE,
                'name' => PruneCommand::TASK_NAME,
                'info' => 'Delete old logs and backups.',
                'enabled' => (bool)env(PF . 'CRON_PRUNE', true),
                'timer' => $checkTaskTimer((string)env(PF . 'CRON_PRUNE_AT', '0 */12 * * *'), '0 */12 * * *'),
                'args' => env(PF . 'CRON_PRUNE_ARGS', '-v'),
            ],
            IndexCommand::TASK_NAME => [
                'command' => IndexCommand::ROUTE,
                'name' => IndexCommand::TASK_NAME,
                'info' => 'Check database for optimal indexes.',
                'enabled' => (bool)env(PF . 'CRON_INDEXES', true),
                'timer' => $checkTaskTimer((string)env(PF . 'CRON_INDEXES_AT', '0 3 * * 3'), '0 3 * * 3'),
                'args' => env(PF . 'CRON_INDEXES_ARGS', '-v'),
            ],
            DispatchCommand::TASK_NAME => [
                'command' => DispatchCommand::ROUTE,
                'name' => DispatchCommand::TASK_NAME,
                'info' => 'Dispatch queued events to their respective listeners.',
                'enabled' => true,
                'timer' => '* * * * *',
                'args' => '-v',
                'hide' => true,
            ],
        ],
    ];

    $config['events'] = [
        'logfile' => ag($config, 'tmpDir') . '/logs/events.' . $logDateFormat . '.log',
        'listeners' => [
            'cache' => new DateInterval(env(PF . 'EVENTS_LISTENERS_CACHE', 'PT1M')),
            'file' => env('APP_EVENTS_FILE', function () use ($config): string|null {
                $file = ag($config, 'path') . '/config/events.php';
                return file_exists($file) ? $file : null;
            }),
            'locations' => [
                __DIR__ . '/../src/API/',
                __DIR__ . '/../src/Backends/',
                __DIR__ . '/../src/Commands/',
                __DIR__ . '/../src/Listeners/',
            ]
        ],
    ];

    return $config;
})();
