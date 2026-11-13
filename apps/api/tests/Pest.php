<?php

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

require_once __DIR__.'/Support/spa_helpers.php';
require_once __DIR__.'/Support/access_helpers.php';
require_once __DIR__.'/Support/demo_helpers.php';
require_once __DIR__.'/Support/master_helpers.php';
require_once __DIR__.'/Support/planning_helpers.php';
require_once __DIR__.'/Support/import_helpers.php';
require_once __DIR__.'/Support/inventory_helpers.php';
require_once __DIR__.'/Support/work_order_helpers.php';
