<?php
declare(strict_types=1);

namespace X68000\Printer\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use X68000\Printer\X68000PrnDecoder;

final class X68000PrnDecoderTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'x68k-prn-decoder-'
            . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($this->tempRoot, 0777, true));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempRoot)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($this->tempRoot);
    }

    public function testDecodesSharpPrinterStream(): void
    {
        $result = (new X68000PrnDecoder())->decode($this->sample());

        self::assertStringContainsString('日本語テスト', $result->text);
        self::assertStringContainsString('ASCII text', $result->text);
        self::assertFalse($result->hasWarnings());
    }

    public function testCountsUnsupportedAndInvalidSequences(): void
    {
        $result = (new X68000PrnDecoder())->decode("\x1BZ\x80");

        self::assertSame("\u{FFFD}", $result->text);
        self::assertSame(1, $result->unknownEscapes);
        self::assertSame(1, $result->invalidPairs);
    }

    public function testCreatesOutputDirectoryAndConvertedFiles(): void
    {
        $inputDirectory = $this->tempRoot . DIRECTORY_SEPARATOR . 'input';
        $outputDirectory = $this->tempRoot . DIRECTORY_SEPARATOR . 'new-output';
        self::assertTrue(mkdir($inputDirectory, 0777, true));

        $inputPath = $inputDirectory . DIRECTORY_SEPARATOR . 'sample.prn';
        self::assertNotFalse(file_put_contents($inputPath, $this->sample()));

        $converted = (new X68000PrnDecoder())->convertFile($inputPath, $outputDirectory);

        $expectedTextPath = $outputDirectory . DIRECTORY_SEPARATOR . 'sample_decoded.txt';
        $expectedHtmlPath = $outputDirectory . DIRECTORY_SEPARATOR . 'sample_decoded.html';
        self::assertSame($expectedTextPath, $converted['text_path']);
        self::assertSame($expectedHtmlPath, $converted['html_path']);
        self::assertFileExists($expectedTextPath);
        self::assertFileExists($expectedHtmlPath);
        self::assertStringContainsString('日本語テスト', (string) file_get_contents($expectedTextPath));
        self::assertStringContainsString('<section class="page">', (string) file_get_contents($expectedHtmlPath));
    }

    public function testRejectsUnknownOutputFormat(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不明な出力形式です: pdf');

        (new X68000PrnDecoder())->convertFile('unused.prn', null, 'pdf');
    }

    public function testRejectsUnreadableInput(): void
    {
        $missingPath = $this->tempRoot . DIRECTORY_SEPARATOR . 'missing.prn';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("PRNを読み込めません: {$missingPath}");

        (new X68000PrnDecoder())->decodeFile($missingPath);
    }

    private function sample(): string
    {
        return "\x1B\x4B\x1C\x53\x06\x06"
            . "\x46\x7C\x4B\x5C\x38\x6C\x25\x46\x25\x39\x25\x48" // 日本語テスト
            . "\r\n"
            . "\x1B\x48ASCII text\r\n\x0C";
    }
}
