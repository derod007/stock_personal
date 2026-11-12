<?php

declare(strict_types=1);

require __DIR__ . '/bin/bootstrap.php';

echo ChartEntryLab\SwingResearch::view(__DIR__ . '/data/ohlcv', $_GET);
