<?php

use App\Kernel;

// If .env file doesn't exist but APP_ENV is set via environment (e.g., Railway),
// tell Symfony Runtime to skip loading .env entirely
if (!file_exists(dirname(__DIR__).'/.env') && (isset($_SERVER['APP_ENV']) || isset($_ENV['APP_ENV']) || getenv('APP_ENV'))) {
    $_SERVER['APP_RUNTIME_OPTIONS'] = [
        'disable_dotenv' => true,
    ];
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
