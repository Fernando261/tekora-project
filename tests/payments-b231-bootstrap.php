<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/Support/PaymentsIntegration/ReferenceLoader.php';
Tests\Support\PaymentsIntegration\ReferenceLoader::register(getenv('TEKORA_B231_REFERENCES') ?: throw new LogicException('Exact external references required.'));
