//go:build linux

package main

import (
	"context"
	"encoding/json"
	"net"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"
)

func TestScalePreservesOtherSlotsAndBounds(t *testing.T) {
	c := fixtureConfig(t)
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- runConfig(ctx, c) }()
	waitPIDs(t, pids, 3)
	initial := waitHealth(t, c, "running")
	scale := func(n int) bool {
		conn, err := net.Dial("unix", controlPath(c))
		if err != nil {
			t.Fatal(err)
		}
		defer conn.Close()
		conn.SetDeadline(time.Now().Add(time.Second))
		json.NewEncoder(conn).Encode(controlRequest{Owner: initial.Owner, Action: "scale", Pool: "imports", Workers: &n})
		var reply controlResponse
		if err := json.NewDecoder(conn).Decode(&reply); err != nil {
			t.Fatal(err)
		}
		return reply.Accepted
	}
	if scale(0) || scale(71) {
		t.Fatal("out-of-range scale accepted")
	}
	if !scale(3) {
		t.Fatal("scale up rejected")
	}
	waitPIDs(t, pids, 5)
	deadline := time.Now().Add(4 * time.Second)
	var h Health
	for time.Now().Before(deadline) {
		h = health(t, c)
		if h.State == "running" && h.Pools[0].Workers == 3 {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	if h.Pools[0].Workers != 3 || len(h.Pools[0].Slots) != 3 || h.Pools[0].Slots[0].PID != initial.Pools[0].Slots[0].PID {
		t.Fatal("scale up replaced retained worker")
	}
	if !scale(1) {
		t.Fatal("scale down rejected")
	}
	deadline = time.Now().Add(4 * time.Second)
	for time.Now().Before(deadline) {
		h = health(t, c)
		if h.State == "running" && len(h.Pools[0].Slots) == 1 {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	if len(h.Pools[0].Slots) != 1 || h.Pools[0].Slots[0].PID != initial.Pools[0].Slots[0].PID || h.Pools[1].Slots[0].PID != initial.Pools[1].Slots[0].PID {
		t.Fatal("scale down affected retained workers")
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func TestInstanceWorkerLimitRejectsOversubscribedScale(t *testing.T) {
	c := fixtureConfig(t)
	c.WorkerLimit = 4
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- runConfig(ctx, c) }()
	waitPIDs(t, pids, 3)
	initial := waitHealth(t, c, "running")
	scale := func(n int) bool {
		conn, err := net.Dial("unix", controlPath(c))
		if err != nil {
			t.Fatal(err)
		}
		defer conn.Close()
		conn.SetDeadline(time.Now().Add(time.Second))
		json.NewEncoder(conn).Encode(controlRequest{Owner: initial.Owner, Action: "scale", Pool: "imports", Workers: &n})
		var reply controlResponse
		if err := json.NewDecoder(conn).Decode(&reply); err != nil {
			t.Fatal(err)
		}
		return reply.Accepted
	}
	if scale(3) {
		t.Fatal("accepted 5 workers against a 4-worker instance limit")
	}
	if !scale(2) {
		t.Fatal("rejected allocation within instance limit")
	}
	waitPIDs(t, pids, 4)
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func TestInstanceWorkerLimitValidation(t *testing.T) {
	c := fixtureConfig(t)
	for _, limit := range []int{-1, 2, 257} {
		c.WorkerLimit = limit
		if c.validate() == nil {
			t.Fatalf("accepted invalid instance limit %d", limit)
		}
	}
	c.WorkerLimit = 3
	if err := c.validate(); err != nil {
		t.Fatal(err)
	}
	c.WorkerLimit = 0
	if c.workerLimit() != 256 {
		t.Fatal("legacy default changed")
	}
}

func TestInstanceLimitIncludesDrainingWorkers(t *testing.T) {
	c := fixtureConfig(t)
	c.WorkerLimit = 3
	c.GraceSeconds = 2
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	t.Setenv("SUPERVISOR_TEST_IGNORE", "1")
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- runConfig(ctx, c) }()
	waitPIDs(t, pids, 3)
	initial := waitHealth(t, c, "running")
	scale := func(pool string, n int) {
		conn, err := net.Dial("unix", controlPath(c))
		if err != nil {
			t.Fatal(err)
		}
		defer conn.Close()
		conn.SetDeadline(time.Now().Add(time.Second))
		json.NewEncoder(conn).Encode(controlRequest{Owner: initial.Owner, Action: "scale", Pool: pool, Workers: &n})
		var reply controlResponse
		if err := json.NewDecoder(conn).Decode(&reply); err != nil {
			t.Fatal(err)
		}
		if !reply.Accepted {
			t.Fatal("reallocation rejected")
		}
	}
	scale("notifications", 1)
	scale("imports", 2)
	time.Sleep(400 * time.Millisecond)
	data, err := os.ReadFile(pids)
	if err != nil {
		t.Fatal(err)
	}
	if len(strings.Fields(string(data))) != 3 {
		t.Fatal("new worker started before draining capacity was reaped")
	}
	ids := waitPIDs(t, pids, 4)
	live := 0
	for _, pid := range ids {
		if syscall.Kill(pid, 0) == nil {
			live++
		}
	}
	if live > 3 {
		t.Fatalf("instance limit exceeded with %d live processes", live)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func TestGroupLimitRejectsScaleDespiteFreeInstanceCapacity(t *testing.T) {
	c := fixtureConfig(t)
	c.WorkerLimit = 10
	for i := range c.Pools {
		c.Pools[i].Group = "shared"
		c.Pools[i].GroupLimit = 3
		c.Pools[i].Queue = c.Pools[i].Name
	}
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- runConfig(ctx, c) }()
	waitPIDs(t, pids, 3)
	initial := waitHealth(t, c, "running")
	conn, err := net.Dial("unix", controlPath(c))
	if err != nil {
		t.Fatal(err)
	}
	conn.SetDeadline(time.Now().Add(time.Second))
	n := 2
	json.NewEncoder(conn).Encode(controlRequest{Owner: initial.Owner, Action: "scale", Pool: "imports", Workers: &n})
	var reply controlResponse
	if err := json.NewDecoder(conn).Decode(&reply); err != nil {
		t.Fatal(err)
	}
	conn.Close()
	if reply.Accepted {
		t.Fatal("group over-allocation accepted with free instance slots")
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func TestGroupConfigurationValidation(t *testing.T) {
	c := fixtureConfig(t)
	for i := range c.Pools {
		c.Pools[i].Group = "shared"
		c.Pools[i].GroupLimit = 3
		c.Pools[i].Queue = c.Pools[i].Name
	}
	if err := c.validate(); err != nil {
		t.Fatal(err)
	}
	c.Pools[1].GroupLimit = 2
	if c.validate() == nil {
		t.Fatal("inconsistent limits accepted")
	}
	c.Pools[0].GroupLimit = 2
	if c.validate() == nil {
		t.Fatal("over-allocated group accepted")
	}
	c.Pools[0].Group = ""
	if c.validate() == nil {
		t.Fatal("anonymous group options accepted")
	}
}

func TestGroupLimitIncludesDrainingWorkers(t *testing.T) {
	c := fixtureConfig(t)
	c.WorkerLimit = 10
	for i := range c.Pools {
		c.Pools[i].Group = "shared"
		c.Pools[i].GroupLimit = 3
		c.Pools[i].Queue = c.Pools[i].Name
	}
	c.GraceSeconds = 2
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	t.Setenv("SUPERVISOR_TEST_IGNORE", "1")
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- runConfig(ctx, c) }()
	waitPIDs(t, pids, 3)
	initial := waitHealth(t, c, "running")
	scale := func(pool string, n int) {
		conn, err := net.Dial("unix", controlPath(c))
		if err != nil {
			t.Fatal(err)
		}
		defer conn.Close()
		conn.SetDeadline(time.Now().Add(time.Second))
		json.NewEncoder(conn).Encode(controlRequest{Owner: initial.Owner, Action: "scale", Pool: pool, Workers: &n})
		var reply controlResponse
		if err := json.NewDecoder(conn).Decode(&reply); err != nil {
			t.Fatal(err)
		}
		if !reply.Accepted {
			t.Fatal("reallocation rejected")
		}
	}
	scale("notifications", 1)
	scale("imports", 2)
	time.Sleep(400 * time.Millisecond)
	data, err := os.ReadFile(pids)
	if err != nil {
		t.Fatal(err)
	}
	if len(strings.Fields(string(data))) != 3 {
		t.Fatal("new worker started before draining capacity was reaped")
	}
	ids := waitPIDs(t, pids, 4)
	live := 0
	for _, pid := range ids {
		if syscall.Kill(pid, 0) == nil {
			live++
		}
	}
	if live > 3 {
		t.Fatalf("group limit exceeded with %d live processes", live)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func TestGroupControlsDrainAndResumeAllMemberQueues(t *testing.T) {
	c := fixtureConfig(t)
	for i := range c.Pools {
		c.Pools[i].Group = "shared"
		c.Pools[i].GroupLimit = 3
		c.Pools[i].Queue = c.Pools[i].Name
	}
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- runConfig(ctx, c) }()
	waitPIDs(t, pids, 3)
	initial := waitHealth(t, c, "running")
	if _, err := sendControl(c, initial.Owner, "pause", "shared"); err != nil {
		t.Fatal(err)
	}
	paused := waitHealth(t, c, "paused")
	for _, p := range paused.Pools {
		if p.State != "paused" {
			t.Fatal("member not paused")
		}
	}
	if _, err := sendControl(c, initial.Owner, "restart", "shared"); err == nil {
		t.Fatal("paused group restarted")
	}
	if _, err := sendControl(c, initial.Owner, "resume", "shared"); err != nil {
		t.Fatal(err)
	}
	waitPIDs(t, pids, 6)
	waitHealth(t, c, "running")
	if _, err := sendControl(c, initial.Owner, "restart", "shared"); err != nil {
		t.Fatal(err)
	}
	waitPIDs(t, pids, 9)
	waitHealth(t, c, "running")
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}
