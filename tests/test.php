<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use X68000\Printer\X68000PrnDecoder;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$decoder = new X68000PrnDecoder();

// Minimal SHARP stream using the same control sequences as Super X-Day X'95.
$sample = "\x1B\x4B\x1C\x53\x06\x06"
    . 'M>L?8!:w%5!<%S%9' // 余命検索サービス
    . "\r\n"
    . '#S#U#P#E#R'       // ＳＵＰＥＲ
    . "\x1B\x48 test\r\n\x0C";

$result = $decoder->decode($sample);
assertTrue(str_contains($result->text, '余命検索サービス'), 'JIS X 0208 text was not decoded');
assertTrue(str_contains($result->text, 'ＳＵＰＥＲ test'), 'kanji/ASCII mode switching failed');
assertTrue(!$result->hasWarnings(), 'valid sample produced warnings');

// Verify that missing output directory and output files are created automatically.
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'x68k-prn-decoder-' . bin2hex(random_bytes(6));
$inputDirectory = $tempRoot . DIRECTORY_SEPARATOR . 'input';
$outputDirectory = $tempRoot . DIRECTORY_SEPARATOR . 'new-output';
assertTrue(mkdir($inputDirectory, 0777, true), 'temporary input directory could not be created');
$inputPath = $inputDirectory . DIRECTORY_SEPARATOR . 'sxdx95.prn';
assertTrue(file_put_contents($inputPath, $sample) !== false, 'temporary PRN could not be created');

$converted = $decoder->convertFile($inputPath, $outputDirectory);
assertTrue($converted['text_path'] === $outputDirectory . DIRECTORY_SEPARATOR . 'sxdx95_decoded.txt', 'unexpected TXT path');
assertTrue($converted['html_path'] === $outputDirectory . DIRECTORY_SEPARATOR . 'sxdx95_decoded.html', 'unexpected HTML path');
assertTrue(is_file($converted['text_path']), 'sxdx95_decoded.txt was not created');
assertTrue(is_file($converted['html_path']), 'sxdx95_decoded.html was not created');

unlink($converted['text_path']);
unlink($converted['html_path']);
unlink($inputPath);
rmdir($outputDirectory);
rmdir($inputDirectory);
rmdir($tempRoot);

echo "PASS: decoding and automatic file creation\n";
