//go:build linux

package main

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"syscall"
	"time"
)

const processTokenKey = "CURRENT_NATIVE_PROCESS_TOKEN"

// These fields are local/private process proof, never a distributed control identity.
// The random token rejects a recycled PID even if its start tick happens to match.
type ProcessIdentity struct {
	BootID string `json:"boot_id"`
	Start  string `json:"start_ticks"`
	UID    uint32 `json:"uid"`
	Token  string `json:"token"`
}

func processToken() (string, error) {
	value := make([]byte, 16)
	if _, err := rand.Read(value); err != nil {
		return "", err
	}
	return hex.EncodeToString(value), nil
}

func boundedRead(path string, limit int64) ([]byte, error) {
	file, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer file.Close()
	value, err := io.ReadAll(io.LimitReader(file, limit+1))
	if err != nil || int64(len(value)) > limit {
		return nil, errors.New("process identity unavailable")
	}
	return value, nil
}

func processIdentity(pid int) (*ProcessIdentity, error) {
	root := "/proc/" + strconv.Itoa(pid)
	stat, err := boundedRead(root+"/stat", 16384)
	if err != nil {
		return nil, err
	}
	// comm may contain spaces and parentheses; fields after the final ')' start at #3.
	end := bytes.LastIndexByte(stat, ')')
	if end < 0 {
		return nil, errors.New("invalid process stat")
	}
	fields := strings.Fields(string(stat[end+1:]))
	if len(fields) < 20 {
		return nil, errors.New("invalid process stat")
	}
	start := fields[19]
	if _, err := strconv.ParseUint(start, 10, 64); err != nil {
		return nil, err
	}
	info, err := os.Stat(root)
	if err != nil {
		return nil, err
	}
	ownership, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		return nil, errors.New("process ownership unavailable")
	}
	boot, err := boundedRead("/proc/sys/kernel/random/boot_id", 128)
	if err != nil {
		return nil, err
	}
	environment, err := boundedRead(root+"/environ", 1048576)
	if err != nil {
		return nil, err
	}
	token := ""
	for _, entry := range bytes.Split(environment, []byte{0}) {
		if bytes.HasPrefix(entry, []byte(processTokenKey+"=")) {
			token = string(entry[len(processTokenKey)+1:])
		}
	}
	if len(token) != 32 {
		return nil, errors.New("process token unavailable")
	}
	if _, err := hex.DecodeString(token); err != nil {
		return nil, err
	}
	return &ProcessIdentity{strings.TrimSpace(string(boot)), start, ownership.Uid, token}, nil
}

// pidfd_open and pidfd_send_signal use these syscall numbers on the supported
// Linux amd64/arm64 release targets. Unsupported kernels/architectures fail closed.
func openProcessFD(pid int) (int, error) {
	if pid <= 1 || (runtime.GOARCH != "amd64" && runtime.GOARCH != "arm64") {
		return -1, errors.New("pidfd cleanup unsupported")
	}
	fd, _, errno := syscall.Syscall(434, uintptr(pid), 0, 0)
	if errno != 0 {
		return -1, errno
	}
	syscall.CloseOnExec(int(fd))
	return int(fd), nil
}
func signalProcessFD(fd int, signal syscall.Signal) error {
	_, _, errno := syscall.Syscall6(424, uintptr(fd), uintptr(signal), 0, 0, 0, 0)
	if errno != 0 {
		return errno
	}
	return nil
}
func processFDExited(fd int) (bool, error) {
	set := syscall.FdSet{}
	if fd < 0 || fd >= len(set.Bits)*64 {
		return false, errors.New("pidfd polling unavailable")
	}
	set.Bits[fd/64] |= 1 << uint(fd%64)
	timeout := syscall.Timeval{}
	count, err := syscall.Select(fd+1, &set, nil, nil, &timeout)
	return count > 0, err
}

func privateFile(path string) (*os.File, error) {
	fd, err := syscall.Open(path, syscall.O_RDWR|syscall.O_CLOEXEC|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return nil, err
	}
	file := os.NewFile(uintptr(fd), path)
	info, err := file.Stat()
	if err != nil {
		file.Close()
		return nil, err
	}
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok || !info.Mode().IsRegular() || info.Mode().Perm()&0077 != 0 || stat.Uid != uint32(os.Geteuid()) || stat.Nlink != 1 {
		file.Close()
		return nil, errors.New("cleanup requires private owned files")
	}
	return file, nil
}

type purgeResult struct {
	Active      bool `json:"active"`
	Signaled    int  `json:"signaled"`
	Exited      int  `json:"exited"`
	Unconfirmed int  `json:"unconfirmed"`
}

func purgeInstance(directory, instance string, signal syscall.Signal, wait time.Duration) (purgeResult, error) {
	result := purgeResult{}
	if !filepath.IsAbs(directory) || !validName.MatchString(instance) || (signal != syscall.SIGTERM && signal != syscall.SIGKILL) || wait < 0 || wait > 300*time.Second {
		return result, errors.New("invalid local cleanup scope")
	}
	info, err := os.Lstat(directory)
	if err != nil {
		return result, err
	}
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok || !info.IsDir() || info.Mode().Perm()&0077 != 0 || stat.Uid != uint32(os.Geteuid()) {
		return result, errors.New("cleanup requires private owned state directory")
	}
	// Open the existing lock only. Never replace/unlink it or create a second owner inode.
	lock, err := privateFile(filepath.Join(directory, instance+".lock"))
	if err != nil {
		return result, errors.New("existing instance ownership unavailable")
	}
	defer lock.Close()
	if err := syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		if errors.Is(err, syscall.EWOULDBLOCK) {
			result.Active = true
			return result, nil
		}
		return result, err
	}
	// A replacement lock path cannot be treated as proof of abandoned ownership.
	current, err := os.Lstat(lock.Name())
	original, statErr := lock.Stat()
	if err != nil || statErr != nil || !os.SameFile(current, original) {
		return result, errors.New("instance lock changed")
	}
	file, err := privateFile(filepath.Join(directory, instance+".json"))
	if err != nil {
		return result, errors.New("private health record unavailable")
	}
	defer file.Close()
	data, err := io.ReadAll(io.LimitReader(file, 8*1024*1024+1))
	if err != nil || len(data) > 8*1024*1024 {
		return result, errors.New("invalid health record")
	}
	var health Health
	if json.Unmarshal(data, &health) != nil || health.Version != 1 || health.Instance != instance || len(health.Owner) != 32 || len(health.Pools) < 1 || len(health.Pools) > maxPools {
		return result, errors.New("invalid health record")
	}
	if _, err := hex.DecodeString(health.Owner); err != nil {
		return result, errors.New("invalid health owner")
	}
	type target struct {
		fd   int
		slot *SlotHealth
	}
	targets := []target{}
	defer func() {
		for _, target := range targets {
			syscall.Close(target.fd)
		}
	}()
	seen := map[int]bool{}
	slots := 0
	for pi := range health.Pools {
		if len(health.Pools[pi].Slots) > maxWorkers {
			return result, errors.New("invalid worker records")
		}
		for si := range health.Pools[pi].Slots {
			slot := &health.Pools[pi].Slots[si]
			slots++
			if slots > 8192 || slot.PID < 0 || (slot.PID > 0 && seen[slot.PID]) {
				return result, errors.New("invalid worker records")
			}
			if slot.PID == 0 {
				continue
			}
			seen[slot.PID] = true
			if len(seen) > maxWorkers {
				return result, errors.New("invalid live worker count")
			}
			// Hold the descriptor BEFORE /proc validation: exit/reuse cannot redirect the signal.
			fd, err := openProcessFD(slot.PID)
			if errors.Is(err, syscall.ESRCH) {
				result.Exited++
				slot.PID = 0
				slot.Identity = nil
				slot.State = "stopped"
				continue
			}
			if err != nil {
				result.Unconfirmed++
				continue
			}
			exited, pollErr := processFDExited(fd)
			if pollErr == nil && exited {
				syscall.Close(fd)
				result.Exited++
				slot.PID = 0
				slot.Identity = nil
				slot.State = "stopped"
				continue
			}
			actual, proofErr := processIdentity(slot.PID)
			if pollErr != nil || proofErr != nil || slot.Identity == nil || *actual != *slot.Identity || actual.UID != uint32(os.Geteuid()) {
				syscall.Close(fd)
				result.Unconfirmed++
				continue
			}
			targets = append(targets, target{fd, slot})
		}
	}
	for _, target := range targets {
		if err := signalProcessFD(target.fd, signal); err != nil && !errors.Is(err, syscall.ESRCH) {
			result.Unconfirmed++
			continue
		}
		result.Signaled++
	}
	deadline := time.Now().Add(wait)
	for {
		remaining := 0
		for _, target := range targets {
			if target.slot.PID == 0 {
				continue
			}
			exited, err := processFDExited(target.fd)
			if err == nil && exited {
				result.Exited++
				target.slot.PID = 0
				target.slot.Identity = nil
				target.slot.State = "stopped"
			} else {
				remaining++
			}
		}
		if remaining == 0 || !time.Now().Before(deadline) {
			result.Unconfirmed += remaining
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	if result.Unconfirmed == 0 {
		health.State = "stopped"
		for pi := range health.Pools {
			health.Pools[pi].State = "stopped"
		}
		if err := (&healthState{value: health}).publish(Config{StateDirectory: directory, Instance: instance}); err != nil {
			return result, errors.New("cleanup completed but final health publication failed")
		}
	}
	return result, nil
}

func purgeCLI(args []string) error {
	if len(args) != 4 {
		return errors.New("usage: -purge private-state-directory instance TERM|KILL wait-seconds")
	}
	signal := syscall.SIGTERM
	switch args[2] {
	case "TERM":
	case "KILL":
		signal = syscall.SIGKILL
	default:
		return errors.New("choose TERM or KILL")
	}
	seconds, err := strconv.Atoi(args[3])
	if err != nil {
		return errors.New("invalid cleanup wait")
	}
	result, err := purgeInstance(args[0], args[1], signal, time.Duration(seconds)*time.Second)
	if err != nil {
		return err
	}
	encoded, err := json.Marshal(result)
	if err != nil {
		return err
	}
	fmt.Println(string(encoded))
	if result.Unconfirmed > 0 {
		return errors.New("some recorded worker outcomes remain unconfirmed; no unproven PID was signaled")
	}
	return nil
}
