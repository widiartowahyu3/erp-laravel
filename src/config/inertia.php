<?php

declare(strict_types=1);

return [
    'pages' => [
        'paths' => [
            resource_path('js/Pages'),
            resource_path('js/pages'),
        ],
        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],
    ],
    'testing' => [
        'ensure_pages_exist' => true,
    ],
];
