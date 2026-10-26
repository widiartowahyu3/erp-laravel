<?php

declare(strict_types=1);

arch('security: do not use dangerous functions in application code')
    ->expect(['eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'unserialize', 'assert', 'create_function', 'extract', 'dd', 'dump', 'var_dump'])
    ->not->toBeUsedIn('App');

arch('security: do not use process execution facades or components in application code')
    ->expect([
        'Illuminate\Support\Facades\Process',
        'Symfony\Component\Process\Process',
    ])
    ->not->toBeUsedIn('App');
