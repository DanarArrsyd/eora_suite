<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            // Fail fast instead of hanging requests and health probes (doc 09).
            'connect_timeout' => env('DB_CONNECT_TIMEOUT', 3),
        ],

        // Web authentication role (doc 25 §2.1): EXECUTE on the web auth_api functions only; used only by App\Platform\Auth.
        'pgsql_auth' => [
            'driver' => 'pgsql',
            'url' => null,
            'host' => env('DB_AUTH_WEB_HOST', env('DB_OWNER_HOST', env('DB_HOST', '127.0.0.1'))),
            'port' => env('DB_AUTH_WEB_PORT', env('DB_OWNER_PORT', env('DB_PORT', '5432'))),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_AUTH_WEB_USERNAME', 'eora_auth_web'),
            'password' => env('DB_AUTH_WEB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'connect_timeout' => env('DB_CONNECT_TIMEOUT', 3),
        ],

        // Secret delivery role (doc 25 §2.1): EXECUTE on the delivery auth_api functions only; separate worker process.
        'pgsql_delivery' => [
            'driver' => 'pgsql',
            'url' => null,
            'host' => env('DB_AUTH_DELIVERY_HOST', env('DB_OWNER_HOST', env('DB_HOST', '127.0.0.1'))),
            'port' => env('DB_AUTH_DELIVERY_PORT', env('DB_OWNER_PORT', env('DB_PORT', '5432'))),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_AUTH_DELIVERY_USERNAME', 'eora_auth_delivery'),
            'password' => env('DB_AUTH_DELIVERY_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'connect_timeout' => env('DB_CONNECT_TIMEOUT', 3),
        ],

        // Outbox relay role (doc 25 §6.4): EXECUTE on outbox_api.claim_events / complete_event / fail_event only — no
        // table privilege, never payloads. Handlers run as eora_app (default connection) in the claimed tenant.
        'pgsql_relay' => [
            'driver' => 'pgsql',
            'url' => null,
            'host' => env('DB_RELAY_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_RELAY_PORT', env('DB_PORT', '5432')),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_RELAY_USERNAME', 'eora_relay'),
            'password' => env('DB_RELAY_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'connect_timeout' => env('DB_CONNECT_TIMEOUT', 3),
        ],

        // Tenant provisioning role (doc 25 §2.5): member of eora_app plus tenant creation. Direct connection (provisioning
        // may bypass the pooler, doc 25 §7). Used by the provisioning command and by test fixtures.
        'pgsql_provisioner' => [
            'driver' => 'pgsql',
            'url' => null,
            'host' => env('DB_PROVISIONER_HOST', env('DB_OWNER_HOST', env('DB_HOST', '127.0.0.1'))),
            'port' => env('DB_PROVISIONER_PORT', env('DB_OWNER_PORT', env('DB_PORT', '5432'))),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_PROVISIONER_USERNAME', 'eora_provisioner'),
            'password' => env('DB_PROVISIONER_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'connect_timeout' => env('DB_CONNECT_TIMEOUT', 3),
        ],

        // Migrations owner (doc 25 §5.1, D-057): DDL and group A schema tests only — never used by requests or jobs.
        // Run migrations with `php artisan migrate --database=pgsql_owner`.
        'pgsql_owner' => [
            'driver' => 'pgsql',
            'url' => null,
            'host' => env('DB_OWNER_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_OWNER_PORT', env('DB_PORT', '5432')),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_OWNER_USERNAME', 'eora_owner'),
            'password' => env('DB_OWNER_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'connect_timeout' => env('DB_CONNECT_TIMEOUT', 3),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'predis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'timeout' => env('REDIS_TIMEOUT', 2),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
