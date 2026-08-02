<?php

/**
 * Milestone 6A Phase 2 — allowlist for External Review AI source-code tools.
 *
 * Paths are relative to repository_root. Anything not listed is unreadable by default.
 * Hard excludes always win, even when a path sits under an allowlisted directory.
 *
 * Assumption: Laravel application code lives under backend/; monorepo docs/ is at repo root.
 * Task shorthand (app/, config/, …) is expressed here as backend/app/, backend/config/, etc.
 */
return [
    /*
    | Absolute or relative monorepo root. Default: parent of Laravel base_path() (= repo root).
    */
    'repository_root' => env('REVIEW_GATEWAY_REPO_ROOT', dirname(base_path())),

    /*
    | Explicit allowlist (trailing slash preferred for directories).
    */
    'allowlist' => [
        'backend/app/',
        'backend/config/',
        'backend/database/migrations/',
        'backend/routes/',
        'backend/tests/',
        'docs/',
    ],

    /*
    | Hard excludes — basename / path fragment patterns (case-insensitive).
    | Applied after allowlist match; deny wins.
    */
    'hard_exclude_basenames' => [
        '.env',
        '.env.example', // still exclude *.env* family via patterns below; listed for clarity
    ],

    'hard_exclude_path_patterns' => [
        '/(^|\/)\.env(\.|$)/i',           // .env, .env.local, .env.production, …
        '/secret/i',
        '/credential/i',
        '/(^|\/)storage\//i',
        '/(^|\/)vendor\//i',
        '/(^|\/)node_modules\//i',
        '/(^|\/)\.git(\/|$)/i',
        '/(^|\/)database\/seeders\//i', // seeders not allowlisted; hard-exclude for belt-and-suspenders
    ],

    /*
    | Demo-password seeders (NOT on allowlist). Flagged for Owner review — see Phase 2 audit doc.
    | Listed here so tooling/tests can assert they remain unreadable.
    */
    'flagged_demo_password_seeders' => [
        'backend/database/seeders/DemoSeeder.php',
        'backend/database/seeders/Milestone4Seeder.php', // demo seeders — excluded from review code read
    ],

    'max_file_bytes' => (int) env('REVIEW_GATEWAY_MAX_FILE_BYTES', 1_048_576),
    'max_search_matches' => (int) env('REVIEW_GATEWAY_MAX_SEARCH_MATCHES', 50),
    'max_search_files' => (int) env('REVIEW_GATEWAY_MAX_SEARCH_FILES', 2000),
    'search_extensions' => [
        'php', 'md', 'js', 'jsx', 'ts', 'tsx', 'json', 'yml', 'yaml', 'css', 'scss', 'blade.php', 'txt', 'xml',
    ],
];
