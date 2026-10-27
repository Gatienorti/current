//go:build linux

package main

import (
	"context"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"
)

func bootstrapFixture(t *testing.T, script string) []string {
	t.Helper()
	d := t.TempDir()
	php := filepath.Join(d, "php-fixture")
	artisan := filepath.Join(d, "artisan")
	if err := os.WriteFile(php, []byte("#!/bin/sh\n"+script), 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(artisan, []byte("fixture"), 0600); err != nil {
		t.Fatal(err)
	}
	return []string{php, artisan}
}
func TestLaravelBootstrapPrivatePipeAndOnce(t *testing.T) {
	args := bootstrapFixture(t, `[ "$2" = "current:collector-config" ] || exit 3
[ "$5" = "--once" ] || exit 4
printf '%s' '{"address":"localhost:6379","prefix":"fixture:","password":"secret","max_age":60,"max_bytes":4096,"once":true}'
`)
	s, e := loadLaravelSettings(context.Background(), append(args, "--once"))
	if e != nil || s.Password != "secret" || !s.Once {
		t.Fatalf("bootstrap failed: %v", e)
	}
}
func TestLaravelBootstrapRejectsOutputAndSanitizesErrors(t *testing.T) {
	for _, script := range []string{"echo secret >&2; exit 2", "printf 'secret-not-json'", "printf '{} trailing-secret'", "head -c 70000 /dev/zero"} {
		_, e := loadLaravelSettings(context.Background(), bootstrapFixture(t, script))
		if e == nil || strings.Contains(e.Error(), "secret") {
			t.Fatal("unsafe error")
		}
	}
}
func TestLaravelBootstrapCancellationReapsProcessGroup(t *testing.T) {
	args := bootstrapFixture(t, "echo $$ > bootstrap.pid\nsleep 30\n")
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { _, e := loadLaravelSettings(ctx, args); done <- e }()
	path := filepath.Join(filepath.Dir(args[0]), "bootstrap.pid")
	var pid int
	end := time.Now().Add(3 * time.Second)
	for time.Now().Before(end) {
		b, e := os.ReadFile(path)
		if e == nil {
			pid, _ = strconv.Atoi(strings.TrimSpace(string(b)))
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	if pid == 0 {
		cancel()
		t.Fatal("bootstrap not started")
	}
	cancel()
	select {
	case e := <-done:
		if e == nil {
			t.Fatal("cancel succeeded")
		}
	case <-time.After(3 * time.Second):
		t.Fatal("cancel did not reap")
	}
	if syscall.Kill(pid, 0) == nil {
		t.Fatal("bootstrap process remains")
	}
}
func TestLaravelBootstrapRejectsArguments(t *testing.T) {
	for _, a := range [][]string{nil, {"php", "artisan"}, {"/php", "/artisan", "--bad"}} {
		if _, e := loadLaravelSettings(context.Background(), a); e == nil {
			t.Fatal("accepted invalid arguments")
		}
	}
}
