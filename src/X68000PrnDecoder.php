<?php
declare(strict_types=1);

namespace X68000\Printer;

use RuntimeException;
use Throwable;

final class X68000PrnDecoder
{
    /**
     * Decode a SHARP printer stream emitted by Super X-Day X'95.
     *
     * ESC K enters JIS X 0208 mode, ESC H returns to ASCII mode,
     * FS S n n is a character-size command, and FF is a page break.
     */
    public function decode(string $data): DecodeResult
    {
        $length = strlen($data);
        $output = '';
        $kanjiMode = false;
        $unknownEscapes = 0;
        $invalidPairs = 0;

        for ($i = 0; $i < $length;) {
            $byte = ord($data[$i]);

            if ($byte === 0x1B) { // ESC
                if ($i + 1 >= $length) {
                    ++$unknownEscapes;
                    ++$i;
                    continue;
                }

                $command = ord($data[$i + 1]);
                if ($command === ord('K')) {
                    $kanjiMode = true;
                } elseif ($command === ord('H')) {
                    $kanjiMode = false;
                } else {
                    ++$unknownEscapes;
                }
                $i += 2;
                continue;
            }

            if ($byte === 0x1C) { // FS
                if ($i + 3 < $length && $data[$i + 1] === 'S') {
                    $i += 4; // FS S horizontal-size vertical-size
                    continue;
                }
                ++$i;
                continue;
            }

            if ($byte === 0x0D) { // CR or CRLF
                if ($i + 1 < $length && ord($data[$i + 1]) === 0x0A) {
                    ++$i;
                }
                $output .= "\n";
                ++$i;
                continue;
            }

            if ($byte === 0x0A) {
                $output .= "\n";
                ++$i;
                continue;
            }

            if ($byte === 0x0C) { // Form feed
                $output .= "\f";
                ++$i;
                continue;
            }

            if ($byte === 0x09) {
                $output .= "\t";
                ++$i;
                continue;
            }

            if ($byte < 0x20) {
                ++$i;
                continue;
            }

            if (!$kanjiMode) {
                if ($byte < 0x80) {
                    $output .= $data[$i];
                    ++$i;
                    continue;
                }

                // Tolerate Shift-JIS characters outside ESC K / ESC H mode.
                $isLead = ($byte >= 0x81 && $byte <= 0x9F) || ($byte >= 0xE0 && $byte <= 0xFC);
                if ($isLead && $i + 1 < $length) {
                    $decoded = $this->decodeCp932Bytes($data[$i] . $data[$i + 1]);
                    if ($decoded !== null) {
                        $output .= $decoded;
                        $i += 2;
                        continue;
                    }
                }

                ++$invalidPairs;
                $output .= "\u{FFFD}";
                ++$i;
                continue;
            }

            if (
                $i + 1 >= $length
                || $byte < 0x21 || $byte > 0x7E
                || ord($data[$i + 1]) < 0x21 || ord($data[$i + 1]) > 0x7E
            ) {
                ++$invalidPairs;
                $output .= "\u{FFFD}";
                ++$i;
                continue;
            }

            $decoded = $this->decodeJisPair($byte, ord($data[$i + 1]));
            if ($decoded === null) {
                ++$invalidPairs;
                $output .= "\u{FFFD}";
            } else {
                $output .= $decoded;
            }
            $i += 2;
        }

        return new DecodeResult(
            ltrim($output, "\r\n"),
            $unknownEscapes,
            $invalidPairs
        );
    }

    public function decodeFile(string $path): DecodeResult
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException("PRNを読み込めません: {$path}");
        }
        return $this->decode($data);
    }

    /**
     * Convert one PRN and save UTF-8 TXT plus printable HTML.
     *
     * @return array{text_path:?string, html_path:?string, result:DecodeResult}
     */
    public function convertFile(
        string $inputPath,
        ?string $outputDirectory = null,
        string $format = 'both'
    ): array
    {
        if (!in_array($format, ['txt', 'html', 'both'], true)) {
            throw new RuntimeException("不明な出力形式です: {$format}");
        }

        $result = $this->decodeFile($inputPath);
        $directory = $outputDirectory ?? dirname($inputPath);
        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException("出力フォルダーを作成できません: {$directory}");
            }
        }

        $base = self::safeBaseName($inputPath);
        $txtPath = null;
        $htmlPath = null;

        if ($format === 'txt' || $format === 'both') {
            $txtPath = $directory . DIRECTORY_SEPARATOR . $base . '_decoded.txt';
            if (@file_put_contents($txtPath, "\xEF\xBB\xBF" . $this->toReadableText($result)) === false) {
                throw new RuntimeException("テキストを保存できません: {$txtPath}");
            }
        }

        if ($format === 'html' || $format === 'both') {
            $htmlPath = $directory . DIRECTORY_SEPARATOR . $base . '_decoded.html';
            if (@file_put_contents($htmlPath, $this->toHtml(basename($inputPath), $result)) === false) {
                throw new RuntimeException("HTMLを保存できません: {$htmlPath}");
            }
        }

        return [
            'text_path' => $txtPath,
            'html_path' => $htmlPath,
            'result' => $result,
        ];
    }

    public function toReadableText(DecodeResult $result): string
    {
        $text = str_replace("\f", "\n\n--- 改ページ ---\n\n", $result->text);
        return rtrim($text, "\r\n") . "\r\n";
    }

    public function toHtml(string $sourceName, DecodeResult $result): string
    {
        $sections = '';
        foreach (explode("\f", $result->text) as $page) {
            $page = trim($page, "\r\n");
            if ($page === '') {
                continue;
            }
            $sections .= '<section class="page"><pre>'
                . htmlspecialchars($page, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . "</pre></section>\n";
        }

        $title = htmlspecialchars($sourceName . ' - 復号結果', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return <<<HTML
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
<style>
@page { size: A4 portrait; margin: 15mm; }
* { box-sizing: border-box; }
body { margin: 0; padding: 24px; background: #e6e7e9; color: #111; }
.page { width: 210mm; min-height: 297mm; margin: 0 auto 24px; padding: 15mm;
        background: #fff; box-shadow: 0 2px 12px #0003; page-break-after: always; }
.page:last-child { page-break-after: auto; }
pre { margin: 0; white-space: pre-wrap; font: 16px/1.45 "BIZ UDPGothic", "MS Gothic", monospace; }
@media print {
  body { padding: 0; background: #fff; }
  .page { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
}
</style>
</head>
<body>
{$sections}</body>
</html>
HTML;
    }

    public static function safeBaseName(string $name): string
    {
        $name = pathinfo(basename($name), PATHINFO_FILENAME);
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', $name) ?? 'decoded';
        return $name === '' ? 'decoded' : $name;
    }

    /**
     * @param int<33, 126> $first
     * @param int<33, 126> $second
     */
    private function decodeJisPair(int $first, int $second): ?string
    {
        $iso2022jp = "\x1B\x24\x42" . chr($first) . chr($second) . "\x1B\x28\x42";
        return $this->convertEncoding($iso2022jp, 'ISO-2022-JP');
    }

    private function decodeCp932Bytes(string $bytes): ?string
    {
        return $this->convertEncoding($bytes, 'CP932');
    }

    private function convertEncoding(string $bytes, string $from): ?string
    {
        if (function_exists('mb_convert_encoding')) {
            try {
                $decoded = mb_convert_encoding($bytes, 'UTF-8', $from);
                return $decoded === false || $decoded === '' ? null : $decoded;
            } catch (Throwable) {
                return null;
            }
        }

        if (function_exists('iconv')) {
            $decoded = @iconv($from, 'UTF-8//IGNORE', $bytes);
            return $decoded === false || $decoded === '' ? null : $decoded;
        }

        throw new RuntimeException('mbstring または iconv 拡張が必要です。');
    }
}
