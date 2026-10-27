//go:build linux

package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"syscall"
	"time"
)

func bootstrapCapability() int { return 1 }

type boundedConfiguration struct {
	buffer   bytes.Buffer
	cancel   context.CancelFunc
	overflow bool
}

func (b *boundedConfiguration) Write(p []byte) (int, error) {
	if b.buffer.Len()+len(p) > 65536 {
		b.overflow = true
		b.cancel()
		return 0, errors.New("configuration too large")
	}
	return b.buffer.Write(p)
}

func loadLaravelSettings(parent context.Context, args []string) (Settings, error) {
	var settings Settings
	if len(args) < 2 || len(args) > 3 || (len(args) == 3 && args[2] != "--once") || !filepath.IsAbs(args[0]) || !filepath.IsAbs(args[1]) {
		return settings, errors.New("invalid Laravel bootstrap arguments")
	}
	info, err := os.Stat(args[1])
	if err != nil || !info.Mode().IsRegular() {
		return settings, errors.New("Laravel bootstrap unavailable")
	}
	ctx, cancel := context.WithTimeout(parent, 60*time.Second)
	defer cancel()
	argv := []string{args[1], "current:collector-config", "--no-interaction", "--no-ansi"}
	if len(args) == 3 {
		argv = append(argv, "--once")
	}
	cmd := exec.CommandContext(ctx, args[0], argv...)
	cmd.Dir = filepath.Dir(args[1])
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true, Pdeathsig: syscall.SIGKILL}
	cmd.Cancel = func() error {
		if cmd.Process == nil {
			return os.ErrProcessDone
		}
		return syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
	}
	cmd.WaitDelay = time.Second
	output := &boundedConfiguration{cancel: cancel}
	cmd.Stdout = output
	cmd.Stderr = io.Discard
	// Linux parent-death signaling follows the creating thread. Keep it alive
	// through Wait, including cancellation, rather than allowing Go to retire it.
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()
	if err = cmd.Run(); err != nil || output.overflow || ctx.Err() != nil {
		return settings, errors.New("Laravel configuration bootstrap failed")
	}
	// Reject trailing output: application startup must not contaminate the pipe.
	if json.Unmarshal(output.buffer.Bytes(), &settings) != nil {
		return settings, errors.New("invalid Laravel configuration output")
	}
	return settings, nil
}
