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
    'ui_lang' => getenv('KIP_UI_LANG') ?: 'en', // UI language pack (App\Lang, app/lang/{code}.php); KIP_UI_LANG overrides
    'og_image' => '',   // absolute or root-relative path; renders og:image/twitter cards when set
    'feeds_full_text' => (bool) getenv('KIP_FEEDS_FULL_TEXT') ?: false, // Atom entries carry the first chapter in <content type="html">
    'ai_crawlers' => getenv('KIP_AI_CRAWLERS') === false || (bool) getenv('KIP_AI_CRAWLERS'), // robots.txt stance; KIP_AI_CRAWLERS=0 disallows GPTBot & co
    'registration_mode' => 'verify',   // open | verify | approval | invite
    'validation_required' => true,     // false: authors self-publish
    'features' => ['news' => true, 'comments' => true, 'contact' => true, 'stats' => true, 'lists' => true, 'search' => true, 'toplists' => true, 'exports' => true, 'feeds' => true, 'directory' => true, 'digest' => true, 'analytics' => true], // flag deploy defaults; feature_flags DB rows are the runtime surface
    'maintenance' => is_file(__DIR__ . '/app/maintenance.lock') || (bool) getenv('KIP_MAINTENANCE'),
    'maintenance_allow' => [],
    'static_cache' => ['enabled' => true, 'dir' => getenv('KIP_STATIC_CACHE_DIR') ?: __DIR__ . '/public/cache'], // KIP_STATIC_CACHE_DIR: tests/imports point pages:build at a throwaway dir
    'public_dir' => __DIR__ . '/public', // web-served root: sitemap/robots regeneration target (never derived from the cache dir, env-free like app_dir)
    'nav_file' => getenv('KIP_NAV_FILE') ?: __DIR__ . '/app/nav.json', // KIP_NAV_FILE: tests/imports point the nav artifact at a throwaway path
    'backups' => ['dir' => getenv('KIP_BACKUP_DIR') ?: __DIR__ . '/app/backups', 'keep_days' => 14], // KIP_BACKUP_DIR: tests/CLI point kip backup at a throwaway dir
    'items_per_page' => 20,
];
