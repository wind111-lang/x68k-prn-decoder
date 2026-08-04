<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$vendorAutoload = $root . '/vendor/autoload.php';
if (!is_file($vendorAutoload)) {
    fwrite(STDERR, "vendor/autoload.php がありません。先に composer install を実行してください。\n");
    exit(1);
}

$dist = $root . '/dist';
if (!is_dir($dist) && !mkdir($dist, 0777, true) && !is_dir($dist)) {
    fwrite(STDERR, "distフォルダーを作成できません。\n");
    exit(1);
}

$output = $dist . '/x68k-prn-decoder.phar';
if (is_file($output) && !unlink($output)) {
    fwrite(STDERR, "既存PHARを削除できません。\n");
    exit(1);
}

$phar = new Phar($output, 0, 'x68k-prn-decoder.phar');
$phar->startBuffering();

$includeRoots = [
    $root . '/src',
    $root . '/vendor',
];

foreach ($includeRoots as $includeRoot) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($includeRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $absolute = $file->getPathname();
        $local = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
        $phar->addFile($absolute, $local);
    }
}

$phar->addFile($root . '/prn-decode.php', 'prn-decode.php');
$phar->setStub(<<<'PHP'
<?php
Phar::mapPhar('x68k-prn-decoder.phar');
require 'phar://x68k-prn-decoder.phar/prn-decode.php';
__HALT_COMPILER();
PHP);
$phar->stopBuffering();

echo $output . PHP_EOL;
