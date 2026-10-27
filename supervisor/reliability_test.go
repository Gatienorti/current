//go:build linux

package main

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"
)

// Exercise the production ten-crashes/minute budget across simultaneous slots,
// including cleanup of a healthy sibling and a subsequent replacement owner.
func TestConcurrentCrashStormDrainsSiblingAndReleasesOwnership(t *testing.T) {
	c := fixtureConfig(t)
	c.Pools[0].Workers, c.Pools[0].MinWorkers, c.Pools[0].MaxWorkers = 2, 1, 2
	c.Pools[0].Command = []string{"/bin/false"}
	c.Pools[1].Workers = 1
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Second)
	defer cancel()
	err := runConfig(ctx, c)
	if err == nil || !strings.Contains(err.Error(), "budget") {
		t.Fatalf("crash budget did not fail the instance: %v", err)
	}
	h := health(t, c)
	if h.State != "failed" {
		t.Fatalf("crash storm state %s", h.State)
	}
	for _, slot := range h.Pools[0].Slots {
		if slot.Starts != 10 || slot.PID != 0 {
			t.Fatalf("unbounded or unreaped crash slot: %+v", slot)
		}
	}
	ids := waitPIDs(t, pids, 1)
	for _, pid := range ids {
		if syscall.Kill(pid, 0) == nil {
			t.Fatalf("healthy sibling %d survived failed instance", pid)
		}
	}
	previous := h.Owner
	c.Pools[0].Command = c.Pools[1].Command
	replacement, stop := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- runConfig(replacement, c) }()
	defer func() { stop(); <-done }()
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		h = health(t, c)
		if h.Owner != previous && h.State == "running" {
			return
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("repaired replacement could not acquire released ownership")
}

// Limit descriptors only in this dedicated subprocess, never in the test runner.
// Verify both exec startup failure and loss of the active health writer under
// real EMFILE conditions, without retaining sibling processes or ownership.
func TestDescriptorExhaustionFailsClosed(t *testing.T) {
	if os.Getenv("CURRENT_DESCRIPTOR_CHILD") != "1" {
		cmd := exec.Command(os.Args[0], "-test.run=^TestDescriptorExhaustionFailsClosed$")
		cmd.Env = append(os.Environ(), "CURRENT_DESCRIPTOR_CHILD=1")
		if out, err := cmd.CombinedOutput(); err != nil {
			t.Fatalf("descriptor child: %v\n%s", err, out)
		}
		return
	}
	var original syscall.Rlimit
	if err := syscall.Getrlimit(syscall.RLIMIT_NOFILE, &original); err != nil {
		t.Fatal(err)
	}
	limited := original
	if limited.Cur > 128 {
		limited.Cur = 128
	}
	if err := syscall.Setrlimit(syscall.RLIMIT_NOFILE, &limited); err != nil {
		t.Fatal(err)
	}
	defer syscall.Setrlimit(syscall.RLIMIT_NOFILE, &original)
	exhaust := func() []*os.File {
		var files []*os.File
		for {
			f, err := os.Open("/dev/null")
			if err != nil {
				return files
			}
			files = append(files, f)
		}
	}
	release := func(files []*os.File) {
		for _, f := range files {
			f.Close()
		}
	}
	files := exhaust()
	err := managedWorker(context.Background(), []string{"/bin/true"}, time.Second, 0, "", 10, nil)
	release(files)
	if err == nil {
		t.Fatal("worker startup succeeded despite exhausted descriptors")
	}

	c := fixtureConfig(t)
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- runConfig(ctx, c) }()
	ids := waitPIDs(t, pids, 3)
	waitHealth(t, c, "running")
	files = exhaust()
	select {
	case err = <-done:
		release(files)
		if err == nil {
			t.Fatal("instance hid descriptor exhaustion")
		}
	case <-time.After(5 * time.Second):
		release(files)
		cancel()
		<-done
		t.Fatal("instance did not stop after health writer exhaustion")
	}
	for _, pid := range ids {
		if syscall.Kill(pid, 0) == nil {
			t.Fatalf("worker %d survived exhaustion", pid)
		}
	}
	lock, err := lockOwner(c)
	if err != nil {
		t.Fatal(err)
	}
	lock.Close()
}
