<?php

require __DIR__ . '/../vendor/autoload.php';
include_once __DIR__ . '/../src/GrokClient.php';

use Rapttor\Grok\GrokClient;

// Initialize client with API key
$env = parse_ini_file(__DIR__ . '/../.env', true);
$apiKey = $env['api']['GROK_API_KEY'];

$grok = new GrokClient($apiKey);

try {
    // Prepare messages
    $messages = [
        [
            'role' => 'user',
            'content' => 'What is the meaning of life, the universe, and everything?'
        ]
    ];

    // Make API call
    $text = $grok->chat($messages)->result(0);

    echo $text;
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
