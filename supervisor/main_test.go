//go:build linux

package main

import (
	"context"
	"os"
	"os/signal"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"
)

func TestFixture(t *testing.T) {
	path := os.Getenv("SUPERVISOR_TEST_PIDS")
	if path == "" {
		return
	}
	f, err := os.OpenFile(path, os.O_APPEND|os.O_WRONLY|os.O_CREATE, 0600)
	if err != nil {
		os.Exit(3)
	}
	_, _ = f.WriteString(strconv.Itoa(os.Getpid()) + "\n")
	_ = f.Close()
	if os.Getenv("SUPERVISOR_TEST_IGNORE") == "1" {
		signal.Ignore(syscall.SIGTERM)
	}
	for {
		time.Sleep(time.Second)
	}
}

func waitPIDs(t *testing.T, path string, n int) []int {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		data, _ := os.ReadFile(path)
		lines := strings.Fields(string(data))
		if len(lines) >= n {
			ids := []int{}
			for _, s := range lines {
				p, _ := strconv.Atoi(s)
				ids = append(ids, p)
			}
			return ids
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatal("workers did not start")
	return nil
}

func TestRestartAndShutdown(t *testing.T) {
	path := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", path)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- supervise(ctx, []string{os.Args[0], "-test.run=^TestFixture$"}, 2, time.Second) }()
	ids := waitPIDs(t, path, 2)
	if err := syscall.Kill(ids[0], syscall.SIGKILL); err != nil {
		t.Fatal(err)
	}
	ids = waitPIDs(t, path, 3)
	cancel()
	select {
	case err := <-done:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("shutdown timed out")
	}
	for _, pid := range ids {
		if syscall.Kill(pid, 0) == nil {
			t.Fatalf("child %d survived", pid)
		}
	}
}

func TestForcedShutdown(t *testing.T) {
	path := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", path)
	t.Setenv("SUPERVISOR_TEST_IGNORE", "1")
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() {
		done <- supervise(ctx, []string{os.Args[0], "-test.run=^TestFixture$"}, 1, 100*time.Millisecond)
	}()
	ids := waitPIDs(t, path, 1)
	time.Sleep(50 * time.Millisecond)
	cancel()
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("forced shutdown timed out")
	}
	if syscall.Kill(ids[0], 0) == nil {
		t.Fatal("child survived")
	}
}

func TestMissingExecutable(t *testing.T) {
	if supervise(context.Background(), []string{"/does-not-exist-current-experiment"}, 2, time.Second) == nil {
		t.Fatal("expected startup error")
	}
}

// max-jobs/max-time and memory recycling exit successfully. They must not
// accumulate the failure backoff and eventually delay every replacement by 5s.
func TestHealthyRecyclingDoesNotAccumulateFailureBackoff(t *testing.T) {
	for _, code := range []string{"0", "12"} {
		t.Run("exit-"+code, func(t *testing.T) {
			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()
			done := make(chan error, 1)
			starts := 0
			go func() {
				done <- managedWorker(ctx, []string{"/bin/sh", "-c", "exit " + code}, time.Second, 0, "", 10,
					func(state string, pid, count int) {
						if state == "running" {
							starts = count
							if count == 5 {
								cancel()
							}
						}
					})
			}()
			select {
			case err := <-done:
				if err != nil {
					t.Fatal(err)
				}
				if starts != 5 {
					t.Fatalf("expected 5 healthy starts, got %d", starts)
				}
			case <-time.After(3 * time.Second):
				cancel()
				<-done
				t.Fatal("healthy worker recycling accumulated failure backoff")
			}

		})
	}
}
