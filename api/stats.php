<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

/* GET → {entered, sold} for the scanner header counter. */

require_role('staff', true);
json_out(gate_counts());
