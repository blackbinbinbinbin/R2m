<?php

return [
    'r2mInfo' => [
        'wili_vote' => [
            'wili_prize_info' => ['key' => 'id','ttl' => '3600','all_key' => '','table' => '']
        ]
    ],

    'redisInfo' => [
        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', 6379),
            'database' => 0,
            'read_timeout' => 60,
        ]
    ]
];