//go:build windows

package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestDecodeSharpPRN(t *testing.T) {
	sample := append([]byte{0x1B, 'K', 0x1C, 'S', 0x06, 0x06}, []byte("M>L?8!:w%5!<%S%9\r\n#S#U#P#E#R")...)
	sample = append(sample, 0x1B, 'H')
	sample = append(sample, []byte(" test\r\n\f")...)

	result := decodeSharpPRN(sample)
	if !strings.Contains(result.Text, "余命検索サービス") {
		t.Fatalf("JIS text was not decoded:\n%s", result.Text)
	}
	if !strings.Contains(result.Text, "ＳＵＰＥＲ test") {
		t.Fatalf("mode switching failed:\n%s", result.Text)
	}
	if result.UnknownEscapes != 0 || result.InvalidPairs != 0 {
		t.Fatalf("unexpected warnings: %+v", result)
	}
}

func TestConvertCreatesOutput(t *testing.T) {
	sample := append([]byte{0x1B, 'K', 0x1C, 'S', 0x06, 0x06}, []byte("M>L?8!:w%5!<%S%9\r\n\f")...)
	root := t.TempDir()
	input := filepath.Join(root, "input", "sxdx95.prn")
	output := filepath.Join(root, "new-output")
	if err := os.MkdirAll(filepath.Dir(input), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(input, sample, 0o644); err != nil {
		t.Fatal(err)
	}

	result, err := decodeFile(input)
	if err != nil {
		t.Fatal(err)
	}
	txtPath, htmlPath, err := convertFile(input, output, "both", result)
	if err != nil {
		t.Fatal(err)
	}
	if txtPath != filepath.Join(output, "sxdx95_decoded.txt") {
		t.Fatalf("unexpected text path: %s", txtPath)
	}
	if htmlPath != filepath.Join(output, "sxdx95_decoded.html") {
		t.Fatalf("unexpected HTML path: %s", htmlPath)
	}
	for _, path := range []string{txtPath, htmlPath} {
		if _, err := os.Stat(path); err != nil {
			t.Fatalf("output not created: %s: %v", path, err)
		}
	}
}
