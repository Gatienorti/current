//go:build linux

package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"sync"
	"syscall"
	"time"
)

const maxGraceSeconds = 86430
const maxWorkers = 4096
const maxPools = 256
const maxQueues = 256
const maxConfigBytes = 4 * 1024 * 1024

type Pool struct {
	Paused     bool     `json:"paused,omitempty"`
	Connection string   `json:"connection,omitempty"`
	Queues     []string `json:"queues,omitempty"`
	Group      string   `json:"group,omitempty"`
	GroupLimit int      `json:"group_limit,omitempty"`
	Queue      string   `json:"queue,omitempty"`
	Name       string   `json:"name"`
	Workers    int      `json:"workers"`
	MinWorkers int      `json:"min_workers"`
	MaxWorkers int      `json:"max_workers"`
	Directory  string   `json:"directory"`
	Command    []string `json:"command"`
}
type Config struct {
	Version        int    `json:"version"`
	Instance       string `json:"instance"`
	StateDirectory string `json:"state_directory"`
	GraceSeconds   int    `json:"grace_seconds"`
	WorkerLimit    int    `json:"worker_limit,omitempty"`
	MemoryLimitMB  int    `json:"memory_limit_mb,omitempty"`
	Pools          []Pool `json:"pools"`
}

var validName = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,63}$`)

func readConfig(path string) (Config, error) {
	var c Config
	f, err := os.Open(path)
	if err != nil {
		return c, err
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil {
		return c, err
	}
	if info.Size() > maxConfigBytes {
		return c, errors.New("configuration exceeds 4 MiB")
	}
	d := json.NewDecoder(io.LimitReader(f, maxConfigBytes+1))
	d.DisallowUnknownFields()
	if err := d.Decode(&c); err != nil {
		return c, err
	}
	var extra any
	if err := d.Decode(&extra); err != io.EOF {
		return c, errors.New("configuration must contain one JSON object")
	}
	return c, c.validate()
}
func (c Config) workerLimit() int {
	if c.WorkerLimit == 0 {
		return 256
	} // Existing version-1 configurations.
	return c.WorkerLimit
}
func (c Config) validate() error {
	if c.MemoryLimitMB < 0 || c.MemoryLimitMB > 65536 {
		return errors.New("invalid supervisor memory limit")
	}
	if c.WorkerLimit < 0 || c.WorkerLimit > maxWorkers || c.Version != 1 || !validName.MatchString(c.Instance) || !filepath.IsAbs(c.StateDirectory) || strings.ContainsRune(c.StateDirectory, 0) || c.GraceSeconds < 1 || c.GraceSeconds > maxGraceSeconds || len(c.Pools) == 0 || len(c.Pools) > maxPools {
		return errors.New("invalid supervisor configuration")
	}
	names := map[string]bool{}
	groupLimits := map[string]int{}
	groupTotals := map[string]int{}
	total := 0
	for _, p := range c.Pools {
		if !validName.MatchString(p.Name) || names[p.Name] || p.MinWorkers < 0 || p.MaxWorkers < 1 || p.MaxWorkers > maxWorkers || p.MinWorkers > p.Workers || p.Workers > p.MaxWorkers || !filepath.IsAbs(p.Directory) || strings.ContainsRune(p.Directory, 0) || len(p.Command) == 0 || len(p.Command) > 64 || !filepath.IsAbs(p.Command[0]) {
			return fmt.Errorf("invalid pool %q", p.Name)
		}
		for _, arg := range p.Command {
			if len(arg) > 32768 || strings.ContainsRune(arg, 0) {
				return errors.New("worker argument exceeds bound")
			}
		}
		if p.Connection != "" {
			if len(p.Connection) > 64 || len(p.Queues) < 1 || len(p.Queues) > maxQueues {
				return errors.New("invalid pool routing metadata")
			}
			for _, queue := range p.Queues {
				if len(queue) < 1 || len(queue) > 128 || strings.ContainsRune(queue, 0) {
					return errors.New("invalid queue metadata")
				}
			}
		} else if len(p.Queues) != 0 {
			return errors.New("queue metadata requires connection")
		}
		if p.Group == "" {
			if p.GroupLimit != 0 || p.Queue != "" {
				return errors.New("ungrouped pool has group options")
			}
		} else {
			if !validName.MatchString(p.Group) || p.GroupLimit < 1 || p.GroupLimit > maxWorkers || len(p.Queue) < 1 || len(p.Queue) > 128 || strings.ContainsRune(p.Queue, 0) {
				return errors.New("invalid pool group")
			}
			if limit, ok := groupLimits[p.Group]; ok && limit != p.GroupLimit {
				return errors.New("inconsistent group limits")
			}
			groupLimits[p.Group] = p.GroupLimit
			groupTotals[p.Group] += p.Workers
		}
		names[p.Name] = true
		total += p.Workers
	}
	for group, total := range groupTotals {
		if names[group] {
			return errors.New("group name collides with worker pool")
		}
		if total > groupLimits[group] {
			return errors.New("allocation exceeds group limit")
		}
	}
	if total > c.workerLimit() {
		return errors.New("total allocation exceeds instance worker limit")
	}
	return nil
}

type SlotHealth struct {
	State    string           `json:"state"`
	PID      int              `json:"pid"`
	Starts   int              `json:"starts"`
	Identity *ProcessIdentity `json:"identity,omitempty"`
}
type PoolHealth struct {
	Connection string       `json:"connection,omitempty"`
	Queues     []string     `json:"queues,omitempty"`
	Group      string       `json:"group,omitempty"`
	GroupLimit int          `json:"group_limit,omitempty"`
	Queue      string       `json:"queue,omitempty"`
	Name       string       `json:"name"`
	State      string       `json:"state"`
	Paused     bool         `json:"paused"`
	Workers    int          `json:"workers"`
	Min        int          `json:"min_workers"`
	Max        int          `json:"max_workers"`
	Slots      []SlotHealth `json:"slots"`
}
type Health struct {
	Version   int          `json:"version"`
	Instance  string       `json:"instance"`
	Owner     string       `json:"owner"`
	PID       int          `json:"pid"`
	State     string       `json:"state"`
	UpdatedAt time.Time    `json:"updated_at"`
	Pools     []PoolHealth `json:"pools"`
}
type healthState struct {
	mu    sync.Mutex
	value Health
}

func (h *healthState) slot(pool, slot int, state string, pid, starts int, identity *ProcessIdentity) {
	h.mu.Lock()
	defer h.mu.Unlock()
	if pid == 0 {
		identity = nil
	}
	h.value.Pools[pool].Slots[slot] = SlotHealth{State: state, PID: pid, Starts: starts, Identity: identity}
}
func (h *healthState) phase(state string) { h.mu.Lock(); defer h.mu.Unlock(); h.value.State = state }
func healthPath(c Config) string          { return filepath.Join(c.StateDirectory, c.Instance+".json") }
func lockOwner(c Config) (*os.File, error) {
	if err := os.MkdirAll(c.StateDirectory, 0700); err != nil {
		return nil, err
	}
	info, err := os.Lstat(c.StateDirectory)
	if err != nil {
		return nil, err
	}
	if !info.IsDir() || info.Mode().Perm()&0077 != 0 {
		return nil, errors.New("state directory must be private (0700), not a symlink")
	}
	fd, err := syscall.Open(filepath.Join(c.StateDirectory, c.Instance+".lock"), syscall.O_RDWR|syscall.O_CREAT|syscall.O_CLOEXEC|syscall.O_NOFOLLOW, 0600)
	if err != nil {
		return nil, err
	}
	f := os.NewFile(uintptr(fd), "supervisor lock")
	if err = syscall.Flock(fd, syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		f.Close()
		return nil, errors.New("instance already owned in this state directory")
	}
	// Never unlink this inode: replacing it would allow two owners.
	return f, nil
}
func (h *healthState) publish(c Config) error {
	h.mu.Lock()
	v := h.value
	v.UpdatedAt = time.Now().UTC()
	v.Pools = append([]PoolHealth(nil), h.value.Pools...)
	paused := 0
	degraded := false
	for i := range v.Pools {
		p := &v.Pools[i]
		live := 0
		allRunning := true
		for _, slot := range p.Slots {
			if slot.PID > 0 {
				live++
			}
			allRunning = allRunning && slot.State == "running"
		}
		switch {
		case v.State == "stopped" || v.State == "failed":
			p.State = v.State
		case v.State == "stopping":
			p.State = "stopping"
		case p.Paused && live == 0:
			p.State = "paused"
			paused++
		case p.Paused:
			p.State = "pausing"
			degraded = true
		case allRunning && live == p.Workers:
			p.State = "running"
		default:
			p.State = "degraded"
			degraded = true
		}
	}
	if v.State == "running" {
		switch {
		case paused == len(v.Pools):
			v.State = "paused"
		case degraded:
			v.State = "degraded"
		case paused > 0:
			v.State = "partial"
		}
	}
	data, err := json.Marshal(v)
	h.mu.Unlock()
	if err != nil {
		return err
	}
	f, err := os.CreateTemp(c.StateDirectory, ".health-*")
	if err != nil {
		return err
	}
	defer os.Remove(f.Name())
	if _, err = f.Write(data); err == nil {
		err = f.Sync()
	}
	closeErr := f.Close()
	if err == nil {
		err = closeErr
	}
	if err != nil {
		return err
	}
	return os.Rename(f.Name(), healthPath(c))
}
func readHealth(c Config, now time.Time) ([]byte, error) {
	f, err := os.Open(healthPath(c))
	if err != nil {
		return nil, err
	}
	defer f.Close()
	var v Health
	if err := json.NewDecoder(io.LimitReader(f, 8*1024*1024)).Decode(&v); err != nil {
		return nil, err
	}
	if v.Version != 1 || v.Instance != c.Instance || v.Owner == "" {
		return nil, errors.New("invalid health snapshot")
	}
	if v.State != "stopped" && v.State != "failed" && (now.Sub(v.UpdatedAt) > 3*time.Second || v.UpdatedAt.After(now.Add(time.Second))) {
		v.State = "stale"
	}
	return json.Marshal(v)
}
func runConfig(parent context.Context, c Config) error {
	if err := c.validate(); err != nil {
		return err
	}
	owner, err := lockOwner(c)
	if err != nil {
		return err
	}
	defer owner.Close()
	token := make([]byte, 16)
	if _, err := rand.Read(token); err != nil {
		return err
	}
	h := &healthState{value: Health{Version: 1, Instance: c.Instance, Owner: hex.EncodeToString(token), PID: os.Getpid(), State: "starting"}}
	for _, p := range c.Pools {
		slots := make([]SlotHealth, p.Workers)
		for i := range slots {
			slots[i].State = "starting"
		}
		h.value.Pools = append(h.value.Pools, PoolHealth{Connection: p.Connection, Queues: p.Queues, Group: p.Group, GroupLimit: p.GroupLimit, Queue: p.Queue, Name: p.Name, State: "starting", Paused: p.Paused, Workers: p.Workers, Min: p.MinWorkers, Max: p.MaxWorkers, Slots: slots})
	}
	if err := h.publish(c); err != nil {
		return err
	}
	ctx, cancel := context.WithCancel(parent)
	defer cancel()
	controls := make(map[string]*poolControl)
	for _, p := range c.Pools {
		controls[p.Name] = newPoolControl(p.Workers)
		controls[p.Name].setPaused(p.Paused)
	}
	closeControl, err := listenControl(c, ctx, func(request controlRequest) error {
		if request.Owner != h.value.Owner {
			return errors.New("stale supervisor owner")
		}
		if ctx.Err() != nil {
			return errors.New("supervisor is stopping")
		}
		if request.Action == "stop" && request.Pool == "" {
			cancel()
			return nil
		}
		if request.Action != "pause" && request.Action != "resume" && request.Action != "restart" && request.Action != "scale" {
			return errors.New("unsupported action")
		}
		control, single := controls[request.Pool]
		selectedControls := map[string]*poolControl{}
		if single {
			selectedControls[request.Pool] = control
		} else {
			for _, p := range c.Pools {
				if request.Pool == "" || p.Group == request.Pool {
					selectedControls[p.Name] = controls[p.Name]
				}
			}
		}
		if len(selectedControls) == 0 {
			return errors.New("unknown pool or group")
		}
		if request.Action == "scale" {
			if !single {
				return errors.New("scale requires a queue worker pool")
			}
			if request.Workers == nil {
				return errors.New("workers required")
			}
			h.mu.Lock()
			total := 0
			selected := -1
			for i, p := range h.value.Pools {
				if p.Name == request.Pool {
					selected = i
				} else {
					total += p.Workers
				}
			}
			if selected < 0 || *request.Workers < h.value.Pools[selected].Min || *request.Workers > h.value.Pools[selected].Max || total+*request.Workers > c.workerLimit() {
				h.mu.Unlock()
				return errors.New("allocation outside limits")
			}
			chosen := h.value.Pools[selected]
			if chosen.Group != "" {
				groupTotal := *request.Workers
				for i, p := range h.value.Pools {
					if i != selected && p.Group == chosen.Group {
						groupTotal += p.Workers
					}
				}
				if groupTotal > chosen.GroupLimit {
					h.mu.Unlock()
					return errors.New("allocation exceeds group limit")
				}
			}
			h.value.Pools[selected].Workers = *request.Workers
			h.mu.Unlock()
			control.setTarget(*request.Workers)
			return nil
		}
		if request.Workers != nil {
			return errors.New("workers only allowed for scale")
		}
		if request.Action == "restart" {
			for _, selected := range selectedControls {
				_, paused, _ := selected.desired()
				if paused {
					return errors.New("pool is paused")
				}
			}
			for _, selected := range selectedControls {
				if err := selected.restart(); err != nil {
					return err
				}
			}
			return nil
		}
		paused := request.Action == "pause"
		for _, selected := range selectedControls {
			selected.setPaused(paused)
		}
		h.mu.Lock()
		for i := range h.value.Pools {
			if _, selected := selectedControls[h.value.Pools[i].Name]; selected {
				h.value.Pools[i].Paused = paused
			}
		}
		h.mu.Unlock()
		return nil
	})
	if err != nil {
		h.phase("failed")
		return errors.Join(err, h.publish(c))
	}
	defer closeControl()
	var wg sync.WaitGroup
	var once sync.Once
	var workerErr error
	capacity := make(chan struct{}, c.workerLimit())
	groupCapacity := map[string]chan struct{}{}
	for _, p := range c.Pools {
		if p.Group != "" {
			groupCapacity[p.Group] = make(chan struct{}, p.GroupLimit)
		}
	}
	for pi, p := range c.Pools {
		wg.Add(1)
		go func(pi int, p Pool) {
			defer wg.Done()
			if err := runPool(ctx, p, pi, controls[p.Name], h, time.Duration(c.GraceSeconds)*time.Second, capacity, groupCapacity[p.Group]); err != nil {
				once.Do(func() { workerErr = fmt.Errorf("pool %s: %w", p.Name, err); cancel() })
			}
		}(pi, p)
	}

	done := make(chan struct{})
	go func() { wg.Wait(); close(done) }()
	h.phase("running")
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	cancelled := ctx.Done()
	var healthErr error
	for {
		select {
		case <-cancelled:
			h.phase("stopping")
			cancelled = nil
		case <-ticker.C:
			if c.MemoryLimitMB > 0 && healthErr == nil {
				var memory runtime.MemStats
				runtime.ReadMemStats(&memory)
				if memory.HeapInuse+memory.StackInuse > uint64(c.MemoryLimitMB)*1024*1024 {
					healthErr = errors.New("supervisor memory limit exceeded")
					cancel()
				}
			}
			if healthErr == nil {
				healthErr = h.publish(c)
				if healthErr != nil {
					cancel()
				}
			}
		case <-done:
			if workerErr != nil || healthErr != nil {
				h.phase("failed")
			} else {
				h.phase("stopped")
				h.mu.Lock()
				for i := range h.value.Pools {
					for j := range h.value.Pools[i].Slots {
						h.value.Pools[i].Slots[j].State = "stopped"
						h.value.Pools[i].Slots[j].PID = 0
					}
				}
				h.mu.Unlock()
			}
			return errors.Join(workerErr, healthErr, h.publish(c))
		}
	}
}
