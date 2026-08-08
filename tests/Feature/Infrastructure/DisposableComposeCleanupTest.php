<?php

it('fails a disposable Compose test target when teardown fails or leaves resources behind', function (): void {
    $makefile = file_get_contents(base_path('Makefile'));

    expect($makefile)->toContain('cleanup() {')
        ->and($makefile)->not->toContain('down -v --remove-orphans >/dev/null 2>&1 || true')
        ->and($makefile)->toContain('test_status="$$?"; cleanup_status=0;')
        ->and($makefile)->toContain('docker ps -aq --filter "label=com.docker.compose.project=$$project"')
        ->and($makefile)->toContain('docker network inspect "$${project}_felio_test_net"')
        ->and($makefile)->toContain('docker volume inspect "$${project}_postgres_data"')
        ->and($makefile)->toContain('Disposable test Compose resources remain for $$project')
        ->and($makefile)->toContain('if [ "$$test_status" -ne 0 ]; then exit "$$test_status"; fi;')
        ->and($makefile)->toContain('if [ "$$cleanup_status" -ne 0 ]; then exit "$$cleanup_status"; fi;');
});
