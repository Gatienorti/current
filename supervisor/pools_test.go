//go:build linux

package main

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"
)

func fixtureConfig(t *testing.T) Config {
	t.Helper()
	root := t.TempDir()
	return Config{Version: 1, Instance: "test", StateDirectory: filepath.Join(root, "state"), GraceSeconds: 1, Pools: []Pool{
		{Name: "imports", Workers: 1, MinWorkers: 1, MaxWorkers: 70, Directory: root, Command: []string{os.Args[0], "-test.run=^TestFixture$"}},
		{Name: "notifications", Workers: 2, MinWorkers: 1, MaxWorkers: 4, Directory: root, Command: []string{os.Args[0], "-test.run=^TestFixture$"}},
	}}
}
func health(t *testing.T, c Config) Health {
	t.Helper()
	data, err := readHealth(c, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	var h Health
	if err := json.Unmarshal(data, &h); err != nil {
		t.Fatal(err)
	}
	return h
}
func waitHealth(t *testing.T, c Config, state string) Health {
	t.Helper()
	end := time.Now().Add(5 * time.Second)
	for time.Now().Before(end) {
		data, err := readHealth(c, time.Now())
		if err == nil {
			var h Health
			if json.Unmarshal(data, &h) == nil && h.State == state {
				return h
			}
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatalf("health did not reach %s", state)
	return Health{}
}
func TestNamedPoolsOwnershipHealthAndRestart(t *testing.T) {
	c := fixtureConfig(t)
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- runConfig(ctx, c) }()
	ids := waitPIDs(t, pids, 3)
	initial := waitHealth(t, c, "running")
	if len(initial.Pools) != 2 || initial.Pools[0].Max != 70 || len(initial.Pools[1].Slots) != 2 {
		t.Fatal("pool allocations/ranges lost")
	}
	if _, err := lockOwner(c); err == nil {
		t.Fatal("duplicate owner accepted")
	}
	if err := syscall.Kill(ids[0], syscall.SIGKILL); err != nil {
		t.Fatal(err)
	}
	ids = waitPIDs(t, pids, 4)
	h := waitHealth(t, c, "running")
	end := time.Now().Add(3 * time.Second)
	reported := false
	for time.Now().Before(end) {
		h = health(t, c)
		starts := 0
		oldPID := false
		for _, p := range h.Pools {
			for _, slot := range p.Slots {
				starts += slot.Starts
				oldPID = oldPID || slot.PID == ids[0]
			}
		}
		if starts == 4 && !oldPID && h.State == "running" {
			reported = true
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	if !reported {
		t.Fatal("replacement not reflected in health")
	}
	if h.Owner != initial.Owner {
		t.Fatal("worker restart changed supervisor identity")
	}
	cancel()
	select {
	case err := <-done:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(4 * time.Second):
		t.Fatal("shutdown timed out")
	}
	h = health(t, c)
	if h.State != "stopped" {
		t.Fatal(h.State)
	}
	for _, p := range h.Pools {
		for _, s := range p.Slots {
			if s.PID != 0 || s.State != "stopped" {
				t.Fatal("stopped snapshot has live slot")
			}
		}
	}
	for _, pid := range ids {
		if syscall.Kill(pid, 0) == nil {
			t.Fatalf("surviving child %d", pid)
		}
	}
	lock, err := lockOwner(c)
	if err != nil {
		t.Fatal(err)
	}
	lock.Close()
}
func TestStaleHealthDoesNotMeanHealthy(t *testing.T) {
	c := fixtureConfig(t)
	lock, err := lockOwner(c)
	if err != nil {
		t.Fatal(err)
	}
	defer lock.Close()
	h := &healthState{value: Health{Version: 1, Instance: c.Instance, Owner: "test-owner", State: "running"}}
	if err := h.publish(c); err != nil {
		t.Fatal(err)
	}
	data, err := readHealth(c, time.Now().Add(5*time.Second))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(data), `"state":"stale"`) {
		t.Fatal(string(data))
	}
}
func TestConfigRejectsUnsafeOrAmbiguousInputs(t *testing.T) {
	cases := []func(*Config){
		func(c *Config) { c.Version = 2 }, func(c *Config) { c.Instance = "../escape" },
		func(c *Config) { c.Pools[1].Name = c.Pools[0].Name }, func(c *Config) { c.Pools[0].Workers = 0 },
		func(c *Config) { c.Pools[0].Workers = 71 }, func(c *Config) { c.Pools[0].Command = []string{"php"} },
		func(c *Config) { c.Pools[0].Workers = 256; c.Pools[0].MaxWorkers = 256 },
	}
	for i, mutate := range cases {
		c := fixtureConfig(t)
		mutate(&c)
		if c.validate() == nil {
			t.Fatalf("invalid config %d accepted", i)
		}
	}
	c := fixtureConfig(t)
	b, _ := json.Marshal(c)
	path := filepath.Join(t.TempDir(), "config.json")
	for _, data := range []string{string(b[:len(b)-1]) + `,"unknown":1}`, string(b) + ` {}`, string(b) + strings.Repeat(" ", 65536)} {
		os.WriteFile(path, []byte(data), 0600)
		if _, err := readConfig(path); err == nil {
			t.Fatal("invalid JSON configuration accepted")
		}
	}
}
func TestCrashBudgetStopsRespawning(t *testing.T) {
	if err := managedWorker(context.Background(), []string{"/bin/false"}, time.Second, 0, "", 3, nil); err == nil || !strings.Contains(err.Error(), "budget") {
		t.Fatalf("expected restart budget error: %v", err)
	}
}
func TestPoolStartupFailureStopsOtherPools(t *testing.T) {
	c := fixtureConfig(t)
	c.Pools[0].Command = []string{"/current-missing-worker"}
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	if err := runConfig(context.Background(), c); err == nil {
		t.Fatal("expected failure")
	}
	if health(t, c).State != "failed" {
		t.Fatal("failure not reported")
	}
	data, _ := os.ReadFile(pids)
	// Any worker that reached fixture readiness must be reaped on sibling startup failure.
	for _, line := range strings.Fields(string(data)) {
		var pid int
		json.Unmarshal([]byte(line), &pid)
		if syscall.Kill(pid, 0) == nil {
			t.Fatal("sibling survived")
		}
	}
}
func TestOwnershipRejectsSharedDirectoryAndSymlinkLock(t *testing.T) {
	c := fixtureConfig(t)
	os.MkdirAll(c.StateDirectory, 0755)
	os.Chmod(c.StateDirectory, 0755)
	if f, err := lockOwner(c); err == nil {
		f.Close()
		t.Fatal("shared state directory accepted")
	}
	os.Chmod(c.StateDirectory, 0700)
	target := filepath.Join(t.TempDir(), "target")
	os.WriteFile(target, []byte("keep"), 0600)
	os.Symlink(target, filepath.Join(c.StateDirectory, c.Instance+".lock"))
	if f, err := lockOwner(c); err == nil {
		f.Close()
		t.Fatal("symlink lock accepted")
	}
}

func TestHealthWriteFailureStopsOwnedWorkers(t *testing.T) {
	c := fixtureConfig(t)
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- runConfig(ctx, c) }()
	ids := waitPIDs(t, pids, 3)
	waitHealth(t, c, "running")
	// A directory cannot be replaced by the atomic snapshot rename.
	if err := os.Remove(healthPath(c)); err != nil {
		t.Fatal(err)
	}
	if err := os.Mkdir(healthPath(c), 0700); err != nil {
		t.Fatal(err)
	}
	select {
	case err := <-done:
		if err == nil {
			t.Fatal("health failure ignored")
		}
	case <-time.After(5 * time.Second):
		t.Fatal("workers did not stop after health failure")
	}
	for _, pid := range ids {
		if syscall.Kill(pid, 0) == nil {
			t.Fatal("worker survived failed health publishing")
		}
	}
	lock, err := lockOwner(c)
	if err != nil {
		t.Fatal(err)
	}
	lock.Close()
}

func TestLongGraceConfigurationAndPromptCooperativeShutdown(t *testing.T) {
	c := fixtureConfig(t)
	c.Pools = c.Pools[:1]
	c.GraceSeconds = 2530
	if err := c.validate(); err != nil {
		t.Fatal(err)
	}
	c.GraceSeconds = maxGraceSeconds + 1
	if c.validate() == nil {
		t.Fatal("unbounded grace accepted")
	}
	c.GraceSeconds = 2530
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- runConfig(ctx, c) }()
	ids := waitPIDs(t, pids, 1)
	cancel()
	select {
	case err := <-done:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(3 * time.Second):
		// Only this test's owned child; prevent a long configured grace leaking it.
		_ = syscall.Kill(ids[0], syscall.SIGKILL)
		t.Fatal("cooperative exit waited for the configured long deadline")
	}
	if syscall.Kill(ids[0], 0) == nil {
		t.Fatal("child survived long-grace shutdown")
	}
}
