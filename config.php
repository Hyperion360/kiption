<?php
return [
    'env'     => getenv('KIP_ENV') ?: 'prod', // prod default, D4 info-disclosure rule
    'db'      => ['dsn' => getenv('KIP_DB_DSN') ?: 'sqlite:' . __DIR__ . '/app/data.sqlite'], // KIP_DB_DSN: tests/CLI point bin/kip at a throwaway DB
    'log_db'  => ['dsn' => 'sqlite:' . __DIR__ . '/app/logs.sqlite', 'retention_days' => 30],
    'cache_db' => ['dsn' => 'sqlite:' . __DIR__ . '/app/cache.sqlite', 'ttl_seconds' => 3600],
    'app_dir' => __DIR__ . '/app',
    'trusted_proxy' => (bool) getenv('KIP_TRUSTED_PROXY'),
    'admin'   => ['enabled' => true],   // /admin CRUD panel, gate: users.is_admin = 1
    'uploads' => ['dir' => __DIR__ . '/public/uploads', 'max_bytes' => 2097152,
                  'ext' => ['png', 'jpg', 'jpeg', 'webp', 'gif']],
    'base_url' => getenv('KIP_BASE_URL') ?: 'http://localhost:8080',   // absolute links in emails
    'mail'     => ['transport' => 'log', 'log_path' => __DIR__ . '/app/mail.log', 'from' => 'noreply@localhost'],
    'site_name' => 'Kiption',
    'og_image' => '',   // absolute or root-relative path; renders og:image/twitter cards when set
    'registration_mode' => 'verify',   // open | verify | approval | invite
    'validation_required' => true,     // false: authors self-publish
    'maintenance' => is_file(__DIR__ . '/app/maintenance.lock') || (bool) getenv('KIP_MAINTENANCE'),
    'maintenance_allow' => [],
    'static_cache' => ['enabled' => true, 'dir' => __DIR__ . '/public/cache'],
    'items_per_page' => 20,
];
