# X68000 PRN復号ツール

X68000実機またはエミュレーターから出力・保存した、X68000用SHARP形式のPRNファイルを解析し、UTF-8テキストと印刷用HTMLへ変換するツールです。
現在の動作確認には、XM6 TypeGから出力されたPRN形式のログを使用しています。

## 必要環境

- Windows EXE：64ビット版Windows（PHP・Composerは不要）
- PHP CLI：PHP 8.4.1以降

## インストール

### Windows EXE

[Releases](https://github.com/wind111-lang/x68k-prn-decoder/releases)から`x68k-prn-decoder-windows-amd64.exe`をダウンロードし、変換するPRNファイルをEXEへドラッグ＆ドロップしてください。PHPやComposerをインストールする必要はありません。

入力したPRNファイルと同じフォルダーへ、TXTと印刷用HTMLが作成されます。

```text
sample.prn
sample_decoded.txt
sample_decoded.html
```

複数のPRNファイルをまとめてドラッグ＆ドロップすることもできます。

### PHP CLI

```shell
git clone git@github.com:wind111-lang/x68k-prn-decoder.git
cd x68k-prn-decoder
composer install
```

別プロジェクトからGitHubリポジトリをComposerパッケージとして使う場合：

```shell
composer config repositories.x68k-prn-decoder vcs https://github.com/wind111-lang/x68k-prn-decoder
composer require wind111-lang/x68k-prn-decoder:dev-main
```

## 使い方

PRNファイルを指定します。

```shell
php prn-decode.php "C:\path\to\sample.prn"
```

入力ファイルと同じフォルダーへ、次のファイルが自動的に作成されます。

```text
sample_decoded.txt
sample_decoded.html
```

ファイルが存在しない場合は新規作成され、すでに存在する場合は最新の復号結果で上書きされます。`--output-dir`で指定したフォルダーが存在しない場合、そのフォルダーも自動作成されます。

複数ファイルやワイルドカードも指定できます。

```shell
php prn-decode.php --format=txt "C:\prints\*.prn"
```

### オプション

```text
--format=both       TXTと印刷用HTMLを保存（既定）
--format=txt        TXTだけ保存
--format=html       印刷用HTMLだけ保存
--output-dir=PATH   指定フォルダーへ保存（未作成なら自動作成）
--stdout            保存せず標準出力へ表示（入力は1ファイル）
-h, --help          ヘルプを表示
```

標準出力へ復号結果を送る例：

```shell
php prn-decode.php --stdout "C:\path\to\sample.prn"
```

印刷用HTMLはブラウザーで開き、印刷画面から紙またはPDFへ出力できます。PRN内の改ページはHTMLのページ区切りとして反映されます。

## クラスとして使う

クラスとして利用できます。`use X68000\Printer\X68000PrnDecoder;`で読み込み、PRNをデコードできます。

```php
use X68000\Printer\X68000PrnDecoder;

$decoder = new X68000PrnDecoder();
$result = $decoder->decodeFile('sample.prn');

echo $decoder->toReadableText($result);
```

変換結果をファイルへ保存する場合：

```php
use X68000\Printer\X68000PrnDecoder;

$decoder = new X68000PrnDecoder();
$converted = $decoder->convertFile(
    'sample.prn',
    __DIR__ . '/decoded',
    'both'
);

echo $converted['text_path'] . PHP_EOL;
echo $converted['html_path'] . PHP_EOL;
```

## 対応範囲

- SHARP形式の漢字モード切り替え（`ESC K`／`ESC H`）
- JIS X 0208の2バイト文字
- ASCIIおよび一部のShift-JIS文字
- `FS S`文字サイズ命令の除去
- 改行と改ページ
- 追記された複数ページのTXT・HTML化

EPSON形式、画像印刷、未知のプリンタ命令には対応していません。

## テスト

```shell
composer test
```

PHPStanによる静的解析は次のコマンドで実行できます。

```shell
composer analyse
```

テストと静的解析をまとめて実行する場合：

```shell
composer check
```
