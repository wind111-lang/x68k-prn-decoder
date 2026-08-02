//go:build windows

package main

import (
	"bytes"
	"fmt"
	"html"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"unsafe"
)

const (
	cpShiftJIS       = 932
	mbErrInvalidChars = 0x00000008
)

var (
	version             = "dev"
	kernel32            = syscall.NewLazyDLL("kernel32.dll")
	procMultiByteToWide = kernel32.NewProc("MultiByteToWideChar")
)

type decodeResult struct {
	Text           string
	UnknownEscapes int
	InvalidPairs   int
}

type options struct {
	Format          string
	OutputDirectory string
	Stdout          bool
	Inputs          []string
}

func main() {
	opts, code := parseOptions(os.Args[1:])
	if code >= 0 {
		os.Exit(code)
	}

	exitCode := 0
	for _, input := range opts.Inputs {
		result, err := decodeFile(input)
		if err != nil {
			fmt.Fprintf(os.Stderr, "変換失敗: %s: %v\n", input, err)
			exitCode = 1
			continue
		}

		if opts.Stdout {
			if opts.Format == "html" {
				fmt.Print(renderHTML(filepath.Base(input), result.Text))
			} else {
				fmt.Print(readableText(result.Text))
			}
		} else {
			txtPath, htmlPath, err := convertFile(input, opts.OutputDirectory, opts.Format, result)
			if err != nil {
				fmt.Fprintf(os.Stderr, "変換失敗: %s: %v\n", input, err)
				exitCode = 1
				continue
			}
			fmt.Fprintf(os.Stderr, "変換しました: %s\n", input)
			if txtPath != "" {
				fmt.Fprintf(os.Stderr, "  %s\n", txtPath)
			}
			if htmlPath != "" {
				fmt.Fprintf(os.Stderr, "  %s\n", htmlPath)
			}
		}

		if result.UnknownEscapes > 0 || result.InvalidPairs > 0 {
			fmt.Fprintf(
				os.Stderr,
				"注意: 未対応制御=%d 不正文字=%d\n",
				result.UnknownEscapes,
				result.InvalidPairs,
			)
		}
	}

	os.Exit(exitCode)
}

func parseOptions(args []string) (options, int) {
	opts := options{Format: "both"}
	optionsEnded := false

	for _, argument := range args {
		if optionsEnded {
			opts.Inputs = append(opts.Inputs, argument)
			continue
		}
		switch {
		case argument == "-h" || argument == "--help":
			fmt.Print(usage())
			return opts, 0
		case argument == "--version":
			fmt.Printf("x68k-prn-decoder %s\n", version)
			return opts, 0
		case argument == "--stdout":
			opts.Stdout = true
		case argument == "--":
			optionsEnded = true
		case strings.HasPrefix(argument, "--format="):
			opts.Format = strings.TrimPrefix(argument, "--format=")
		case strings.HasPrefix(argument, "--output-dir="):
			opts.OutputDirectory = strings.TrimPrefix(argument, "--output-dir=")
		case strings.HasPrefix(argument, "-"):
			fmt.Fprintf(os.Stderr, "不明なオプション: %s\n\n%s", argument, usage())
			return opts, 2
		default:
			opts.Inputs = append(opts.Inputs, argument)
		}
	}

	if opts.Format != "txt" && opts.Format != "html" && opts.Format != "both" {
		fmt.Fprintln(os.Stderr, "--format は txt、html、both のいずれかです。")
		return opts, 2
	}

	var expanded []string
	for _, input := range opts.Inputs {
		if strings.ContainsAny(input, "*?") {
			matches, _ := filepath.Glob(input)
			if len(matches) > 0 {
				expanded = append(expanded, matches...)
				continue
			}
		}
		expanded = append(expanded, input)
	}
	opts.Inputs = expanded

	if len(opts.Inputs) == 0 {
		fmt.Fprint(os.Stderr, usage())
		return opts, 2
	}
	if opts.Stdout && len(opts.Inputs) != 1 {
		fmt.Fprintln(os.Stderr, "--stdout では入力を1ファイルだけ指定してください。")
		return opts, 2
	}
	if opts.Stdout && opts.Format == "both" {
		opts.Format = "txt"
	}

	return opts, -1
}

func usage() string {
	return `X68000 PRN復号ツール

使い方:
  x68k-prn-decoder-windows-amd64.exe [options] file.prn [file2.prn ...]

オプション:
  --format=both       TXTと印刷用HTMLを保存（既定）
  --format=txt        TXTだけ保存
  --format=html       印刷用HTMLだけ保存
  --output-dir=PATH   指定フォルダーへ保存
  --stdout            保存せず標準出力へ表示（入力は1ファイル）
  --version           バージョンを表示
  -h, --help          このヘルプを表示

`
}

func decodeFile(path string) (decodeResult, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return decodeResult{}, fmt.Errorf("PRNを読み込めません: %w", err)
	}
	return decodeSharpPRN(data), nil
}

func decodeSharpPRN(data []byte) decodeResult {
	var out strings.Builder
	kanjiMode := false
	unknownEscapes := 0
	invalidPairs := 0

	for i := 0; i < len(data); {
		b := data[i]

		switch b {
		case 0x1B: // ESC
			if i+1 >= len(data) {
				unknownEscapes++
				i++
				continue
			}
			switch data[i+1] {
			case 'K':
				kanjiMode = true
			case 'H':
				kanjiMode = false
			default:
				unknownEscapes++
			}
			i += 2
			continue
		case 0x1C: // FS
			if i+3 < len(data) && data[i+1] == 'S' {
				i += 4
				continue
			}
			i++
			continue
		case '\r':
			if i+1 < len(data) && data[i+1] == '\n' {
				i++
			}
			out.WriteByte('\n')
			i++
			continue
		case '\n':
			out.WriteByte('\n')
			i++
			continue
		case '\f':
			out.WriteByte('\f')
			i++
			continue
		case '\t':
			out.WriteByte('\t')
			i++
			continue
		}

		if b < 0x20 {
			i++
			continue
		}

		if !kanjiMode {
			if b < 0x80 {
				out.WriteByte(b)
				i++
				continue
			}

			byteCount := 1
			if (b >= 0x81 && b <= 0x9F) || (b >= 0xE0 && b <= 0xFC) {
				byteCount = 2
			}
			if i+byteCount <= len(data) {
				if r, ok := cp932ToRune(data[i : i+byteCount]); ok {
					out.WriteRune(r)
					i += byteCount
					continue
				}
			}

			out.WriteRune('\uFFFD')
			invalidPairs++
			i++
			continue
		}

		if i+1 >= len(data) || b < 0x21 || b > 0x7E || data[i+1] < 0x21 || data[i+1] > 0x7E {
			out.WriteRune('\uFFFD')
			invalidPairs++
			i++
			continue
		}

		if r, ok := jisPairToRune(b, data[i+1]); ok {
			out.WriteRune(r)
		} else {
			out.WriteRune('\uFFFD')
			invalidPairs++
		}
		i += 2
	}

	return decodeResult{
		Text:           strings.TrimLeft(out.String(), "\r\n"),
		UnknownEscapes: unknownEscapes,
		InvalidPairs:   invalidPairs,
	}
}

func jisPairToRune(j1, j2 byte) (rune, bool) {
	row := int(j1 - 0x21)
	cell := int(j2 - 0x21)
	lead := (row >> 1) + 0x81
	if lead > 0x9F {
		lead += 0x40
	}

	var trail int
	if row%2 == 0 {
		trail = cell + 0x40
		if trail >= 0x7F {
			trail++
		}
	} else {
		trail = cell + 0x9F
	}

	return cp932ToRune([]byte{byte(lead), byte(trail)})
}

func cp932ToRune(encoded []byte) (rune, bool) {
	if len(encoded) == 0 {
		return 0, false
	}
	var wide [2]uint16
	ret, _, _ := procMultiByteToWide.Call(
		cpShiftJIS,
		mbErrInvalidChars,
		uintptr(unsafe.Pointer(&encoded[0])),
		uintptr(len(encoded)),
		uintptr(unsafe.Pointer(&wide[0])),
		uintptr(len(wide)),
	)
	if ret == 0 {
		return 0, false
	}
	return rune(wide[0]), true
}

func convertFile(input, outputDirectory, format string, result decodeResult) (string, string, error) {
	directory := outputDirectory
	if directory == "" {
		directory = filepath.Dir(input)
	}
	if err := os.MkdirAll(directory, 0o755); err != nil {
		return "", "", fmt.Errorf("出力フォルダーを作成できません: %w", err)
	}

	base := safeBaseName(input)
	var txtPath, htmlPath string
	if format == "txt" || format == "both" {
		txtPath = filepath.Join(directory, base+"_decoded.txt")
		data := append([]byte{0xEF, 0xBB, 0xBF}, []byte(readableText(result.Text))...)
		if err := os.WriteFile(txtPath, data, 0o644); err != nil {
			return "", "", fmt.Errorf("テキストを保存できません: %w", err)
		}
	}
	if format == "html" || format == "both" {
		htmlPath = filepath.Join(directory, base+"_decoded.html")
		if err := os.WriteFile(htmlPath, []byte(renderHTML(filepath.Base(input), result.Text)), 0o644); err != nil {
			return txtPath, "", fmt.Errorf("HTMLを保存できません: %w", err)
		}
	}

	return txtPath, htmlPath, nil
}

func safeBaseName(path string) string {
	base := strings.TrimSuffix(filepath.Base(path), filepath.Ext(path))
	var out strings.Builder
	for _, r := range base {
		if r < 0x20 || strings.ContainsRune(`\/:*?"<>|`, r) {
			out.WriteRune('_')
		} else {
			out.WriteRune(r)
		}
	}
	if out.Len() == 0 {
		return "decoded"
	}
	return out.String()
}

func readableText(text string) string {
	text = strings.ReplaceAll(text, "\f", "\n\n--- 改ページ ---\n\n")
	text = strings.TrimRight(text, "\r\n") + "\n"
	return strings.ReplaceAll(text, "\n", "\r\n")
}

func renderHTML(sourceName, text string) string {
	var body bytes.Buffer
	for _, page := range strings.Split(text, "\f") {
		page = strings.Trim(page, "\r\n")
		if page == "" {
			continue
		}
		fmt.Fprintf(&body, "<section class=\"page\"><pre>%s</pre></section>\n", html.EscapeString(page))
	}

	return fmt.Sprintf(`<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>%s - 復号結果</title>
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
%s</body>
</html>
`, html.EscapeString(sourceName), body.String())
}
