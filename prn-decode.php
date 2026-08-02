<?php
declare(strict_types=1);

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    // Keep the repository checkout directly runnable before `composer install`.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'X68000\\Printer\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

use X68000\Printer\X68000PrnDecoder;

const X68K_PRN_DECODER_VERSION = 'v0.1.2';

function usage(): string
{
    return <<<TEXT
X68000 PRN復号ツール

使い方:
  php prn-decode.php [options] file.prn [file2.prn ...]

オプション:
  --format=both       TXTと印刷用HTMLを保存（既定）
  --format=txt        TXTだけ保存
  --format=html       印刷用HTMLだけ保存
  --output-dir=PATH   指定フォルダーへ保存
  --stdout            保存せず標準出力へ表示（入力は1ファイル）
  --version           バージョンを表示
  -h, --help          このヘルプを表示

例:
  php prn-decode.php sxdx95.prn
  php prn-decode.php --format=txt *.prn
  php prn-decode.php --stdout sxdx95.prn

TEXT;
}

if (!in_array(PHP_SAPI, ['cli', 'micro'], true)) {
    fwrite(STDERR, "このツールはCLI専用です。\n");
    exit(2);
}

$format = 'both';
$outputDirectory = null;
$stdout = false;
$inputs = [];
$optionsEnded = false;

foreach (array_slice($argv, 1) as $argument) {
    if ($optionsEnded) {
        $inputs[] = $argument;
        continue;
    }
    if ($argument === '--') {
        $optionsEnded = true;
        continue;
    }
    if ($argument === '-h' || $argument === '--help') {
        echo usage();
        exit(0);
    }
    if ($argument === '--version') {
        echo 'x68k-prn-decoder ' . X68K_PRN_DECODER_VERSION . PHP_EOL;
        exit(0);
    }
    if ($argument === '--stdout') {
        $stdout = true;
        continue;
    }
    if (str_starts_with($argument, '--format=')) {
        $format = substr($argument, strlen('--format='));
        continue;
    }
    if (str_starts_with($argument, '--output-dir=')) {
        $outputDirectory = substr($argument, strlen('--output-dir='));
        continue;
    }
    if (str_starts_with($argument, '-')) {
        fwrite(STDERR, "不明なオプション: {$argument}\n\n" . usage());
        exit(2);
    }
    $inputs[] = $argument;
}

// Windows shells generally do not expand wildcards for native commands.
$expandedInputs = [];
foreach ($inputs as $input) {
    if (strpbrk($input, '*?') !== false) {
        $matches = glob($input, GLOB_NOSORT);
        if ($matches !== false && $matches !== []) {
            array_push($expandedInputs, ...$matches);
            continue;
        }
    }
    $expandedInputs[] = $input;
}
$inputs = $expandedInputs;

if (!in_array($format, ['txt', 'html', 'both'], true)) {
    fwrite(STDERR, "--format は txt、html、both のいずれかです。\n");
    exit(2);
}
if ($inputs === []) {
    fwrite(STDERR, usage());
    exit(2);
}
if ($stdout && count($inputs) !== 1) {
    fwrite(STDERR, "--stdout では入力を1ファイルだけ指定してください。\n");
    exit(2);
}
if ($stdout && $format === 'both') {
    $format = 'txt';
}

$decoder = new X68000PrnDecoder();
$exitCode = 0;

foreach ($inputs as $inputPath) {
    try {
        $result = $decoder->decodeFile($inputPath);

        if ($stdout) {
            if ($format === 'html') {
                echo $decoder->toHtml(basename($inputPath), $result);
            } else {
                echo $decoder->toReadableText($result);
            }
        } else {
            $converted = $decoder->convertFile($inputPath, $outputDirectory, $format);
            fwrite(STDERR, "変換しました: {$inputPath}\n");
            if ($converted['text_path'] !== null) {
                fwrite(STDERR, "  {$converted['text_path']}\n");
            }
            if ($converted['html_path'] !== null) {
                fwrite(STDERR, "  {$converted['html_path']}\n");
            }
        }

        if ($result->hasWarnings()) {
            fwrite(
                STDERR,
                "注意: 未対応制御={$result->unknownEscapes} 不正文字={$result->invalidPairs}\n"
            );
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "変換失敗: {$inputPath}: {$e->getMessage()}\n");
        $exitCode = 1;
    }
}

exit($exitCode);
