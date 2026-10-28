//go:build linux

package main

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"
)

func TestSpawnedProcessIdentityRetriesOnlyUnavailableProof(t *testing.T) {
	marker := filepath.Join(t.TempDir(), "ready")
	t.Setenv("SUPERVISOR_TEST_PIDS", marker)
	token, err := processToken()
	if err != nil {
		t.Fatal(err)
	}
	cmd, fd, err := startManagedProcess([]string{os.Args[0], "-test.run=^TestFixture$"}, "", token)
	if err != nil {
		t.Fatal(err)
	}
	defer func() {
		_ = cmd.Process.Kill()
		_ = cmd.Wait()
		if fd >= 0 {
			syscall.Close(fd)
		}
	}()
	if fd < 0 {
		t.Skip("Kernel does not support spawn pidfds; workers intentionally have no purge proof")
	}
	waitPIDs(t, marker, 1)
	actual, err := processIdentity(cmd.Process.Pid)
	if err != nil {
		t.Fatal(err)
	}
	for _, first := range []string{"valid", "unavailable", "nil", "different-token"} {
		t.Run(first, func(t *testing.T) {
			calls := 0
			proof := spawnedProcessIdentity(context.Background(), cmd.Process.Pid, fd, token, func(pid int) (*ProcessIdentity, error) {
				calls++
				if pid != cmd.Process.Pid {
					t.Fatal("read a different process")
				}
				if calls == 1 {
					switch first {
					case "unavailable":
						return nil, errors.New("injected first read unavailable")
					case "nil":
						return nil, nil
					case "different-token":
						other := *actual
						other.Token = strings.Repeat("0", 32)
						return &other, nil
					}
				}
				return actual, nil
			})
			if first == "different-token" {
				if proof != nil || calls != 1 {
					t.Fatal("retried or accepted mismatched proof")
				}
			} else {
				expectedCalls := 2
				if first == "valid" {
					expectedCalls = 1
				}
				if proof != actual || calls != expectedCalls {
					t.Fatalf("proof=%v reads=%d want=%d", proof, calls, expectedCalls)
				}
			}
		})
	}
	t.Run("bounded", func(t *testing.T) {
		calls := 0
		start := time.Now()
		proof := spawnedProcessIdentity(context.Background(), cmd.Process.Pid, fd, token, func(int) (*ProcessIdentity, error) {
			calls++
			return nil, errors.New("persistent unavailable proof")
		})
		elapsed := time.Since(start)
		if proof != nil || calls < 2 || calls > 21 || elapsed < 90*time.Millisecond || elapsed > time.Second {
			t.Fatalf("proof=%v reads=%d elapsed=%v", proof, calls, elapsed)
		}
	})
	t.Run("cancellation", func(t *testing.T) {
		ctx, cancel := context.WithCancel(context.Background())
		defer cancel()
		calls := 0
		proof := spawnedProcessIdentity(ctx, cmd.Process.Pid, fd, token, func(int) (*ProcessIdentity, error) {
			calls++
			cancel()
			return actual, nil
		})
		if proof != nil || calls != 1 {
			t.Fatal("accepted proof after cancellation")
		}
		if spawnedProcessIdentity(ctx, cmd.Process.Pid, fd, token, func(int) (*ProcessIdentity, error) {
			t.Fatal("read after cancellation")
			return actual, nil
		}) != nil {
			t.Fatal("accepted proof for cancelled capture")
		}
	})
	t.Run("exit during read", func(t *testing.T) {
		calls := 0
		proof := spawnedProcessIdentity(context.Background(), cmd.Process.Pid, fd, token, func(int) (*ProcessIdentity, error) {
			calls++
			if err := cmd.Process.Kill(); err != nil {
				t.Fatal(err)
			}
			if err := cmd.Wait(); err == nil {
				t.Fatal("fixture did not exit after kill")
			}
			return actual, nil
		})
		if proof != nil || calls != 1 {
			t.Fatal("accepted proof after spawn pidfd exited")
		}
		if spawnedProcessIdentity(context.Background(), cmd.Process.Pid, fd, token, func(int) (*ProcessIdentity, error) {
			t.Fatal("read after spawn pidfd exited")
			return actual, nil
		}) != nil {
			t.Fatal("accepted proof for an exited process")
		}
	})
}

func TestSpawnedProcessIdentityRefusesUnavailablePidfd(t *testing.T) {
	if spawnedProcessIdentity(context.Background(), os.Getpid(), -1, strings.Repeat("a", 32), func(int) (*ProcessIdentity, error) {
		t.Fatal("read identity without an anchored spawn pidfd")
		return nil, nil
	}) != nil {
		t.Fatal("accepted proof without an anchored spawn pidfd")
	}
}
