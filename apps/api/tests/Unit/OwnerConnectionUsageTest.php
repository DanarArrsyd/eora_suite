<?php

declare(strict_types=1);

// The migrations-owner connection exists for migrations and group A tests only (doc 25 §10): application code must
// never reference it, so a code path can never run as the role that bypasses nothing but owns every table.
it('never references the migrations-owner connection from application code', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app'));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'pgsql_owner')) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
