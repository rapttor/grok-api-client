<?php
$image = false;
$text = true;

require __DIR__ . '/../vendor/autoload.php';
include_once __DIR__ . '/../src/GrokClient.php';

use Rapttor\Grok\GrokClient;

// Initialize client with API key
$env = parse_ini_file(__DIR__ . '/../.env', true);
$apiKey = $env['api']['GROK_API_KEY'];
$grok = new GrokClient($apiKey);

if ($text)
    try {
        // Prepare messages
        $message = '5 most active X users today, as json array';

        // Make API call
        $text = $grok->chat($message)->result(0);

        var_dump('TEXT:', $text);
    } catch (Exception $e) {
        echo 'Error: ' . $e->getMessage();
    }

if ($image)
    try {
        // Prepare messages

        // Make API call
        $image = $grok
            ->image(['prompt' => 'A cat in a tree']);

        $response = $image->response();

        var_dump('RESPONSE:', $response);
    } catch (Exception $e) {
        echo 'Error: ' . $e->getMessage();
    }
