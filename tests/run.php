<?php

declare(strict_types=1);

/*
 * ABIHA test runner (no dependencies):
 *   php tests/run.php              every suite (UnitTest, IntegrationTest, HttpTest, then any other tests/*Test.php)
 *   php tests/run.php unit         only tests/UnitTest.php  (name = file name without "Test.php", case-insensitive)
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/Fixtures.php';
require __DIR__ . '/Http.php';

$files = array_map('basename', glob(__DIR__ . '/*Test.php') ?: []);
$order = ['UnitTest.php', 'IntegrationTest.php', 'HttpTest.php'];
usort($files, static function (string $a, string $b) use ($order): int {
    $ia = array_search($a, $order, true);
    $ib = array_search($b, $order, true);
    return [$ia === false ? 99 : $ia, $a] <=> [$ib === false ? 99 : $ib, $b];
});
$only = isset($argv[1]) ? strtolower($argv[1]) : null;
$start = microtime(true);

foreach ($files as $file) {
    $name = strtolower(substr($file, 0, -strlen('Test.php')));
    if ($only !== null && $only !== $name) {
        continue;
    }
    try {
        require __DIR__ . '/' . $file;
    } catch (\Throwable $e) {
        T::$failures[] = "[{$name}] crashed in " . T::$current . ': ' . $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
}

$secs = round(microtime(true) - $start, 2);
if (T::$failures) {
    fwrite(STDOUT, "\nFAILURES\n");
    foreach (T::$failures as $f) {
        fwrite(STDOUT, "  x {$f}\n");
    }
}
fwrite(STDOUT, sprintf("\n%d passed, %d failed in %ss\n", T::$pass, count(T::$failures), $secs));
exit(T::$failures ? 1 : 0);
