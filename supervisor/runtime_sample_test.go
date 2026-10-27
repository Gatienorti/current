//go:build linux

package main

import (
	"context"
	"io"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"
)

func TestRuntimeSampleChild(t *testing.T) {
	if os.Getenv("CURRENT_RUNTIME_CHILD") != "1" {
		return
	}
	switch os.Args[len(os.Args)-1] {
	case "echo":
		data, _ := io.ReadAll(os.Stdin)
		os.Stdout.Write(data)
	case "hang":
		if path := os.Getenv("CURRENT_RUNTIME_MARKER"); path != "" {
			os.WriteFile(path, []byte(strconv.Itoa(os.Getpid())), 0600)
		}
		time.Sleep(30 * time.Second)
	case "overflow":
		os.Stdout.WriteString(strings.Repeat("x", runtimeSampleLimit+1))
	}
	os.Exit(0)
}
func TestRuntimeSampleBoundsInputExecutionAndOutput(t *testing.T) {
	binary, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	t.Setenv("CURRENT_RUNTIME_CHILD", "1")
	command := func(mode string) []string { return []string{binary, "-test.run=TestRuntimeSampleChild", "--", mode} }
	output, err := runRuntimeSample(context.Background(), command("echo"), []byte("private-pipe"), 2*time.Second)
	if err != nil || string(output) != "private-pipe" {
		t.Fatal("pipe mismatch", err)
	}
	for _, mode := range []string{"hang", "overflow"} {
		start := time.Now()
		output, err = runRuntimeSample(context.Background(), command(mode), nil, 300*time.Millisecond)
		if err == nil || len(output) != 0 || time.Since(start) > 2*time.Second {
			t.Fatalf("%s was not bounded: %v", mode, err)
		}
	}
}

func TestRuntimeSampleCancellationReapsChild(t *testing.T) {
	binary, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	marker := filepath.Join(t.TempDir(), "pid")
	t.Setenv("CURRENT_RUNTIME_CHILD", "1")
	t.Setenv("CURRENT_RUNTIME_MARKER", marker)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() {
		_, err := runRuntimeSample(ctx, []string{binary, "-test.run=TestRuntimeSampleChild", "--", "hang"}, nil, 4*time.Second)
		done <- err
	}()
	var pid int
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		if b, err := os.ReadFile(marker); err == nil {
			pid, _ = strconv.Atoi(string(b))
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	if pid == 0 {
		t.Fatal("child did not start")
	}
	cancel()
	select {
	case err := <-done:
		if err == nil {
			t.Fatal("cancelled sample succeeded")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("cancelled child did not exit")
	}
	if syscall.Kill(pid, 0) == nil {
		t.Fatal("sample child survived cancellation")
	}
}
