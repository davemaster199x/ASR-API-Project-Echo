<?php
/**
 * Echo Config
 */

// Server Configuration
    $config = [
        'paths'    => [
            'files' => 'files/',
        ],
        'db'       => [
            'type' => 'mysql',
            'host' => 'localhost',
            'name' => 'echo_db',
            'user' => 'root',
            'pass' => ''
        ],
        'limits'   => [
            'max_file_size' => 20 // Max supplied file size in MB
        ],
        'main' => [
            'log_level' => 'debug'  // Options: debug, info, error, none
        ]
    ];