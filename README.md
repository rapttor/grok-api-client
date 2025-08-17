# GROK API PHP Client

A simple PHP client library for interacting with xAI's GROK API - Based on grok-4 curl example

## Installation

Install via Composer:

```bash
composer require RapTToR/grok-api-client
```

## Usage

```php

<?php
use RapTToR\Grok\Grok;
$XAI_API_KEY = getenv('XAI_API_KEY');
echo  new Grok($XAI_API_KEY)->chat("What is the meaning of life, the universe, and everything?")->result(0);
?>
```

- The library is based on Grok Curl example
- Does not require any other dependencies
