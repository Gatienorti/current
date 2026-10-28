//go:build linux

package main

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestStartPausedDoesNotLaunchUntilResumed(t *testing.T) {
	c := fixtureConfig(t)
	c.Pools = c.Pools[:1]
	c.Pools[0].Paused = true
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- runConfig(ctx, c) }()
	h := waitHealth(t, c, "paused")
	if data, _ := os.ReadFile(pids); len(data) != 0 {
		t.Fatal("paused startup launched workers")
	}
	conn, err := net.Dial("unix", controlPath(c))
	if err != nil {
		t.Fatal(err)
	}
	conn.SetDeadline(time.Now().Add(time.Second))
	json.NewEncoder(conn).Encode(controlRequest{Owner: h.Owner, Action: "resume", Pool: c.Pools[0].Name})
	var response controlResponse
	err = json.NewDecoder(conn).Decode(&response)
	conn.Close()
	if err != nil || !response.Accepted {
		t.Fatalf("resume: %v %+v", err, response)
	}
	waitPIDs(t, pids, c.Pools[0].Workers)
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func TestIdleZeroFloorCanScaleUpAndDrainToZero(t *testing.T) {
	c := fixtureConfig(t)
	c.Pools = c.Pools[:1]
	c.Pools[0].Workers, c.Pools[0].MinWorkers = 0, 0
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- runConfig(ctx, c) }()
	initial := waitHealth(t, c, "running")
	if len(initial.Pools[0].Slots) != 0 {
		t.Fatal("zero target has live slots")
	}
	scale := func(n int) {
		conn, err := net.Dial("unix", controlPath(c))
		if err != nil {
			t.Fatal(err)
		}
		defer conn.Close()
		conn.SetDeadline(time.Now().Add(time.Second))
		json.NewEncoder(conn).Encode(controlRequest{Owner: initial.Owner, Action: "scale", Pool: c.Pools[0].Name, Workers: &n})
		var response controlResponse
		if err := json.NewDecoder(conn).Decode(&response); err != nil || !response.Accepted {
			t.Fatalf("scale: %v %+v", err, response)
		}
	}
	scale(2)
	waitPIDs(t, pids, 2)
	scale(0)
	deadline := time.Now().Add(4 * time.Second)
	for time.Now().Before(deadline) {
		h := health(t, c)
		if h.Pools[0].Workers == 0 && len(h.Pools[0].Slots) == 0 {
			cancel()
			if err := <-done; err != nil {
				t.Fatal(err)
			}
			return
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("pool did not drain to zero")
}

func TestSupervisorMemoryLimitDrainsAndReportsFailure(t *testing.T) {
	c := fixtureConfig(t)
	c.MemoryLimitMB = 1 // Deliberately below the runtime footprint, never a production recommendation.
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	err := runConfig(ctx, c)
	if err == nil || !strings.Contains(err.Error(), "memory limit exceeded") {
		t.Fatalf("memory outcome: %v", err)
	}
	h := health(t, c)
	if h.State != "failed" {
		t.Fatalf("state = %s", h.State)
	}
	for _, pool := range h.Pools {
		for _, slot := range pool.Slots {
			if slot.PID != 0 {
				t.Fatal("memory exit left a worker recorded alive")
			}
		}
	}
}

func TestLargePlanValidationKeepsExplicitBudget(t *testing.T) {
	c := fixtureConfig(t)
	c.WorkerLimit = maxWorkers
	c.Pools = nil
	for i := 0; i < 40; i++ {
		p := fixtureConfig(t).Pools[0]
		p.Name = "pool-" + strings.Repeat("x", i+1)
		p.Workers = 70
		p.MaxWorkers = 70
		c.Pools = append(c.Pools, p)
	}
	if err := c.validate(); err != nil {
		t.Fatal(err)
	}
	c.WorkerLimit = 256
	if c.validate() == nil {
		t.Fatal("large plan ignored selected budget")
	}
}

func TestInstanceControlsIncludeGroupedAndPriorityPools(t *testing.T) {
	c := fixtureConfig(t)
	c.Pools[0].Group, c.Pools[0].GroupLimit, c.Pools[0].Queue = "balanced", 70, "one"
	c.Pools[0].Connection, c.Pools[0].Queues = "redis", []string{"one"}
	pids := filepath.Join(t.TempDir(), "pids")
	t.Setenv("SUPERVISOR_TEST_PIDS", pids)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- runConfig(ctx, c) }()
	waitPIDs(t, pids, 3)
	initial := waitHealth(t, c, "running")
	control := func(action string) {
		conn, err := net.Dial("unix", controlPath(c))
		if err != nil {
			t.Fatal(err)
		}
		defer conn.Close()
		conn.SetDeadline(time.Now().Add(time.Second))
		json.NewEncoder(conn).Encode(controlRequest{Owner: initial.Owner, Action: action})
		var response controlResponse
		if err := json.NewDecoder(conn).Decode(&response); err != nil || !response.Accepted {
			t.Fatalf("%s: %v %+v", action, err, response)
		}
	}
	control("pause")
	paused := waitHealth(t, c, "paused")
	for _, pool := range paused.Pools {
		if pool.State != "paused" {
			t.Fatal("instance pause omitted a pool")
		}
	}
	control("resume")
	waitPIDs(t, pids, 6)
	waitHealth(t, c, "running")
	control("restart")
	waitPIDs(t, pids, 9)
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func TestOrderedQueueArgumentMatchesSupportedRoutingLimit(t *testing.T) {
	c := fixtureConfig(t)
	c.Pools = c.Pools[:1]
	for i := 0; i < maxQueues; i++ {
		c.Pools[0].Queues = append(c.Pools[0].Queues, strings.Repeat("q", 96)+fmt.Sprintf("%04d", i))
	}
	c.Pools[0].Connection = "redis"
	c.Pools[0].Command = []string{os.Args[0], "--queue=" + strings.Join(c.Pools[0].Queues, ",")}
	data, err := json.Marshal(c)
	if err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(t.TempDir(), "large-routing.json")
	if err := os.WriteFile(path, data, 0600); err != nil {
		t.Fatal(err)
	}
	decoded, err := readConfig(path)
	if err != nil || len(decoded.Pools[0].Queues) != maxQueues {
		t.Fatalf("supported routing rejected: %v", err)
	}
	c.Pools[0].Command[1] = strings.Repeat("x", 32769)
	if c.validate() == nil {
		t.Fatal("oversized argument accepted")
	}
}
