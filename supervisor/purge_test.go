//go:build linux

package main

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"syscall"
	"testing"
	"time"
	"unsafe"
)

func TestPurgeSupervisorFixture(t *testing.T) {
	path := os.Getenv("CURRENT_PURGE_CONFIG")
	if path == "" {
		return
	}
	config, err := readConfig(path)
	if err != nil {
		os.Exit(3)
	}
	if runConfig(context.Background(), config) != nil {
		os.Exit(4)
	}
}

func TestPurgeKillsOnlyProvenWorkersAfterActualSupervisorLoss(t *testing.T) {
	c := fixtureConfig(t)
	c.Pools = c.Pools[:1]
	c.Pools[0].Workers = 1
	c.Pools[0].Command = []string{os.Args[0], "-test.run=^TestFixture$"}
	config, _ := json.Marshal(c)
	path := filepath.Join(t.TempDir(), "config.json")
	if err := os.WriteFile(path, config, 0600); err != nil {
		t.Fatal(err)
	}
	pids := filepath.Join(t.TempDir(), "pids")
	cmd := exec.Command(os.Args[0], "-test.run=^TestPurgeSupervisorFixture$")
	cmd.Env = append(os.Environ(), "CURRENT_PURGE_CONFIG="+path, "SUPERVISOR_TEST_PIDS="+pids, "SUPERVISOR_TEST_IGNORE=1")
	if err := cmd.Start(); err != nil {
		t.Fatal(err)
	}
	defer func() { _ = cmd.Process.Kill(); _ = cmd.Wait() }()
	pid := waitPIDs(t, pids, 1)[0]
	deadline := time.Now().Add(5 * time.Second)
	var h Health
	for time.Now().Before(deadline) {
		data, _ := os.ReadFile(healthPath(c))
		_ = json.Unmarshal(data, &h)
		if len(h.Pools) == 1 && len(h.Pools[0].Slots) == 1 && h.Pools[0].Slots[0].Identity != nil {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	if len(h.Pools) == 0 || h.Pools[0].Slots[0].Identity == nil {
		t.Fatal("spawn identity was not published")
	}
	fd, err := openProcessFD(pid)
	if err != nil {
		t.Fatal(err)
	}
	defer syscall.Close(fd)
	defer signalProcessFD(fd, syscall.SIGKILL)
	if result, err := purgeInstance(c.StateDirectory, c.Instance, syscall.SIGKILL, time.Second); err != nil || !result.Active || result.Signaled != 0 {
		t.Fatalf("active generation was touched: %+v %v", result, err)
	}
	// SIGKILL prevents the supervisor's graceful path. Worker ignores Pdeathsig TERM.
	if err := cmd.Process.Kill(); err != nil {
		t.Fatal(err)
	}
	_ = cmd.Wait()
	if exited, err := processFDExited(fd); err != nil || exited {
		t.Fatalf("fixture worker did not survive supervisor loss: %v %v", exited, err)
	}
	result, err := purgeInstance(c.StateDirectory, c.Instance, syscall.SIGKILL, time.Second)
	if err != nil || result.Active || result.Signaled != 1 || result.Exited != 1 || result.Unconfirmed != 0 {
		t.Fatalf("orphan cleanup: %+v %v", result, err)
	}
	stopped := health(t, c)
	if stopped.State != "stopped" || stopped.Pools[0].Slots[0].PID != 0 {
		t.Fatal("confirmed cleanup did not publish terminal health")
	}
	// Repeating does not signal a PID remembered by an earlier generation.
	result, err = purgeInstance(c.StateDirectory, c.Instance, syscall.SIGKILL, 0)
	if err != nil || result.Signaled != 0 {
		t.Fatalf("cleanup was not idempotent: %+v %v", result, err)
	}
}

func TestPurgeRejectsStaleSiblingAndMissingProcessIdentities(t *testing.T) {
	c := fixtureConfig(t)
	lock, err := lockOwner(c)
	if err != nil {
		t.Fatal(err)
	}
	lock.Close()
	token, err := processToken()
	if err != nil {
		t.Fatal(err)
	}
	marker := filepath.Join(t.TempDir(), "sibling")
	sibling := exec.Command(os.Args[0], "-test.run=^TestFixture$")
	sibling.Env = append(os.Environ(), processTokenKey+"="+token, "SUPERVISOR_TEST_PIDS="+marker)
	if err := sibling.Start(); err != nil {
		t.Fatal(err)
	}
	defer func() { _ = sibling.Process.Kill(); _ = sibling.Wait() }()
	if ids := waitPIDs(t, marker, 1); ids[0] != sibling.Process.Pid {
		t.Fatal("sibling readiness marker belongs to another process")
	}
	actual, err := processIdentity(sibling.Process.Pid)
	if err != nil {
		t.Fatal(err)
	}
	for _, field := range []string{"token", "start", "boot", "uid", "missing"} {
		t.Run(field, func(t *testing.T) {
			proof := *actual
			switch field {
			case "token":
				proof.Token = strings.Repeat("0", 32)
			case "start":
				proof.Start += "0"
			case "boot":
				proof.BootID += "-old"
			case "uid":
				proof.UID++
			}
			var identity *ProcessIdentity = &proof
			if field == "missing" {
				identity = nil
			}
			h := Health{Version: 1, Instance: c.Instance, Owner: strings.Repeat("a", 32), State: "running", Pools: []PoolHealth{{Name: "imports", Slots: []SlotHealth{{State: "running", PID: sibling.Process.Pid, Identity: identity}}}}}
			data, _ := json.Marshal(h)
			if err := os.WriteFile(healthPath(c), data, 0600); err != nil {
				t.Fatal(err)
			}
			result, err := purgeInstance(c.StateDirectory, c.Instance, syscall.SIGKILL, 0)
			if err != nil || result.Unconfirmed != 1 || result.Signaled != 0 {
				t.Fatalf("unproven PID accepted: %+v %v", result, err)
			}
			if err := sibling.Process.Signal(syscall.Signal(0)); err != nil {
				t.Fatalf("sibling was killed: %v", err)
			}
		})
	}
}

func TestPurgeRefusesUnsafeStateAndOldRecords(t *testing.T) {
	c := fixtureConfig(t)
	lock, err := lockOwner(c)
	if err != nil {
		t.Fatal(err)
	}
	lock.Close()
	h := Health{Version: 1, Instance: c.Instance, Owner: strings.Repeat("a", 32), State: "running", Pools: []PoolHealth{{Slots: []SlotHealth{{State: "running", PID: os.Getpid()}}}}}
	data, _ := json.Marshal(h)
	if err := os.WriteFile(healthPath(c), data, 0600); err != nil {
		t.Fatal(err)
	}
	result, err := purgeInstance(c.StateDirectory, c.Instance, syscall.SIGKILL, 0)
	if err != nil || result.Signaled != 0 || result.Unconfirmed != 1 {
		t.Fatalf("legacy record was trusted: %+v %v", result, err)
	}
	if _, err := purgeInstance(c.StateDirectory, "../other", syscall.SIGKILL, 0); err == nil {
		t.Fatal("path traversal accepted")
	}
	if err := os.Chmod(c.StateDirectory, 0755); err != nil {
		t.Fatal(err)
	}
	if _, err := purgeInstance(c.StateDirectory, c.Instance, syscall.SIGKILL, 0); err == nil {
		t.Fatal("public directory accepted")
	}
	_ = os.Chmod(c.StateDirectory, 0700)
	if err := os.Remove(healthPath(c)); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(filepath.Join(t.TempDir(), "other"), healthPath(c)); err != nil {
		t.Fatal(err)
	}
	if _, err := purgeInstance(c.StateDirectory, c.Instance, syscall.SIGKILL, 0); err == nil {
		t.Fatal("symlink record accepted")
	}
	if _, err := openProcessFD(1); err == nil {
		t.Fatal("system init accepted as worker")
	}
}

// Runs in a disposable subprocess: the restrictive filter must never affect
// the parent test runner or any unrelated worker.
func TestPurgePidfdBlockedFixture(t *testing.T) {
	if os.Getenv("CURRENT_PIDFD_BLOCKED_FIXTURE") != "1" {
		return
	}
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()
	// Deny clone only when CLONE_PIDFD is requested; ordinary clone remains
	// permitted. This reproduces old-kernel/errno-based seccomp capability loss.
	filters := []syscall.SockFilter{
		{Code: syscall.BPF_LD | syscall.BPF_W | syscall.BPF_ABS, K: 0},
		{Code: syscall.BPF_JMP | syscall.BPF_JEQ | syscall.BPF_K, K: syscall.SYS_CLONE, Jt: 1},
		{Code: syscall.BPF_RET | syscall.BPF_K, K: 0x7fff0000},
		{Code: syscall.BPF_LD | syscall.BPF_W | syscall.BPF_ABS, K: 16},
		{Code: syscall.BPF_ALU | syscall.BPF_AND | syscall.BPF_K, K: syscall.CLONE_PIDFD},
		{Code: syscall.BPF_JMP | syscall.BPF_JEQ | syscall.BPF_K, K: syscall.CLONE_PIDFD, Jt: 1},
		{Code: syscall.BPF_RET | syscall.BPF_K, K: 0x7fff0000},
		{Code: syscall.BPF_RET | syscall.BPF_K, K: 0x00050000 | uint32(syscall.EPERM)},
	}
	program := syscall.SockFprog{Len: uint16(len(filters)), Filter: &filters[0]}
	if _, _, errno := syscall.RawSyscall6(syscall.SYS_PRCTL, 38, 1, 0, 0, 0, 0); errno != 0 {
		t.Fatal(errno)
	}
	if _, _, errno := syscall.RawSyscall6(syscall.SYS_PRCTL, 22, 2, uintptr(unsafe.Pointer(&program)), 0, 0, 0); errno != 0 {
		t.Fatal(errno)
	}
	token, _ := processToken()
	// Ensure the kernel really rejects the original explicit-pidfd path.
	proofFD := -1
	original := exec.Command("/bin/true")
	original.SysProcAttr = &syscall.SysProcAttr{PidFD: &proofFD}
	if err := original.Run(); !errors.Is(err, syscall.EPERM) {
		t.Fatalf("filter did not reject CLONE_PIDFD: %v", err)
	}
	cmd, fd, err := startManagedProcess([]string{"/bin/true"}, "", token)
	if err != nil {
		t.Fatalf("normal worker could not start without cleanup proof: %v", err)
	}
	if fd != -1 {
		t.Fatalf("unsupported spawn fabricated cleanup proof: %d", fd)
	}
	if err := cmd.Wait(); err != nil {
		t.Fatal(err)
	}
}

func TestPurgeUnavailablePidfdDoesNotPreventWorkerStartup(t *testing.T) {
	cmd := exec.Command(os.Args[0], "-test.run=^TestPurgePidfdBlockedFixture$")
	cmd.Env = append(os.Environ(), "CURRENT_PIDFD_BLOCKED_FIXTURE=1")
	if output, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("pidfd compatibility fixture: %v\n%s", err, output)
	}
}

func TestPurgeReadsHealthAboveLegacyOneMiBBound(t *testing.T) {
	c := fixtureConfig(t)
	lock, err := lockOwner(c)
	if err != nil {
		t.Fatal(err)
	}
	lock.Close()
	h := Health{Version: 1, Instance: c.Instance, Owner: strings.Repeat("a", 32), State: "stopped", Pools: []PoolHealth{{Slots: []SlotHealth{{State: "stopped", PID: 0}}}}}
	data, _ := json.Marshal(h)
	data = append([]byte(strings.Repeat(" ", 1048580)), data...)
	if err := os.WriteFile(healthPath(c), data, 0600); err != nil {
		t.Fatal(err)
	}
	result, err := purgeInstance(c.StateDirectory, c.Instance, syscall.SIGKILL, 0)
	if err != nil || result.Signaled != 0 || result.Unconfirmed != 0 {
		t.Fatalf("large valid health refused: %+v %v", result, err)
	}
}
