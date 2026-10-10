<?php
/**
 * Regression tests for OneUptime date shapes.
 * Run: php tests/oneuptime-dates.php
 */
define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-arcwind-init-oneuptime.php';

$cases = [
    ['2026-10-10T15:00:00Z', 1791644400],
    [['value' => '2026-10-10T15:00:00Z'], 1791644400],
    [['date' => ['value' => '2026-10-10T15:00:00Z']], 1791644400],
    [['$date' => '2026-10-10T15:00:00Z'], 1791644400],
    [1791644400, 1791644400],
    [1791644400000, 1791644400],
    [null, null],
    [['unexpected' => 'field'], null],
    [['value' => []], null],
    [true, null],
];
foreach ($cases as $index => [$input, $expected]) {
    $result = ArcWind_Init_OneUptime::parse_api_timestamp($input);
    if ($result !== $expected) {
        fwrite(STDERR, "Case $index failed: " . var_export($result, true) . "\n");
        exit(1);
    }
}
echo "OneUptime date regression tests passed.\n";
