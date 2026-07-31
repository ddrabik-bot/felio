<?php

it('provides a fresh PostgreSQL migration smoke command', function () {
    $makefile = file_get_contents(base_path('Makefile'));

    expect($makefile)
        ->toContain('smoke-migrations:')
        ->toContain('php artisan migrate:fresh --force')
        ->toContain('php artisan migrate:status');
});
