//go:build linux

package main

import (
	"context"
	"os"
	"path/filepath"
	"syscall"
	"testing"
	"time"
)

func TestOwnedPoolPauseResumeRestartAndStop(t *testing.T) {
	c := fixtureConfig(t)
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- runConfig(ctx, c) }()
	waitPIDs(t, pids, 3)
	h := waitHealth(t, c, "running")
	if _, err := sendControl(c, "wrong-owner", "pause", "imports"); err == nil {
		t.Fatal("stale owner accepted")
	}
	if _, err := sendControl(c, h.Owner, "pause", "missing"); err == nil {
		t.Fatal("unknown pool accepted")
	}
	if _, err := sendControl(c, h.Owner, "execute", "imports"); err == nil {
		t.Fatal("arbitrary control accepted")
	}
	oldPID := h.Pools[0].Slots[0].PID
	if _, err := sendControl(c, h.Owner, "pause", "imports"); err != nil {
		t.Fatal(err)
	}
	h = waitHealth(t, c, "partial")
	if h.Pools[0].State != "paused" || h.Pools[0].Slots[0].PID != 0 || h.Pools[1].State != "running" {
		t.Fatal("pause affected wrong pool")
	}
	if syscall.Kill(oldPID, 0) == nil {
		t.Fatal("paused child still alive")
	}
	if _, err := sendControl(c, h.Owner, "pause", "imports"); err != nil {
		t.Fatal(err)
	}
	if _, err := sendControl(c, h.Owner, "restart", "imports"); err == nil {
		t.Fatal("restart resumed paused pool")
	}
	if _, err := sendControl(c, h.Owner, "resume", "imports"); err != nil {
		t.Fatal(err)
	}
	waitPIDs(t, pids, 4)
	h = waitHealth(t, c, "running")
	if h.Pools[0].Slots[0].Starts != 2 || h.Pools[0].Slots[0].PID == oldPID {
		t.Fatal("resume did not restart allocation")
	}
	before := h.Pools[0].Slots[0].PID
	other := h.Pools[1].Slots[0].PID
	if _, err := sendControl(c, h.Owner, "restart", "imports"); err != nil {
		t.Fatal(err)
	}
	deadline := time.Now().Add(4 * time.Second)
	for time.Now().Before(deadline) {
		h = health(t, c)
		if h.Pools[0].Slots[0].Starts == 3 && h.Pools[0].Slots[0].PID > 0 {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	if h.Pools[0].Slots[0].Starts != 3 || h.Pools[0].Slots[0].PID == before || h.Pools[1].Slots[0].PID != other {
		t.Fatal("restart failed or affected other pool")
	}
	if syscall.Kill(before, 0) == nil {
		t.Fatal("previous generation still alive")
	}
	if _, err := sendControl(c, h.Owner, "stop", ""); err != nil {
		t.Fatal(err)
	}
	select {
	case err := <-done:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(4 * time.Second):
		t.Fatal("stop timed out")
	}
	if health(t, c).State != "stopped" {
		t.Fatal("stop state missing")
	}
	if _, err := os.Lstat(controlPath(c)); !os.IsNotExist(err) {
		t.Fatal("control socket not removed")
	}
}
