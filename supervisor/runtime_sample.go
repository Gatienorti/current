//go:build linux

package main

import (
	"bytes"
	"context"
	"errors"
	"io"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"syscall"
	"time"
)

const runtimeSampleLimit = 262144

type runtimeSampleOutput struct {
	buffer bytes.Buffer
	cancel context.CancelFunc
}

func (b *runtimeSampleOutput) Write(p []byte) (int, error) {
	if b.buffer.Len()+len(p) > runtimeSampleLimit {
		b.cancel()
		return 0, errors.New("runtime output exceeds bound")
	}
	return b.buffer.Write(p)
}

func runtimeSampleCLI(args []string) error {
	if len(args) != 2 || !filepath.IsAbs(args[0]) || !filepath.IsAbs(args[1]) {
		return errors.New("invalid runtime command")
	}
	info, err := os.Stat(args[1])
	if err != nil || !info.Mode().IsRegular() {
		return errors.New("runtime bootstrap unavailable")
	}
	// Reading the pipe is included in the parent's five-second guard. PHP starts
	// only after bounded input has arrived; no secret appears in argv or a file.
	input, err := io.ReadAll(io.LimitReader(os.Stdin, runtimeSampleLimit+1))
	if err != nil || len(input) > runtimeSampleLimit {
		return errors.New("invalid runtime input")
	}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()
	watchParent(ctx, os.Getppid(), stop)
	output, err := runRuntimeSample(ctx, []string{args[0], args[1], "current:runtime-snapshot", "--no-interaction", "--no-ansi"}, input, 4*time.Second)
	if err != nil {
		return err
	}
	_, err = os.Stdout.Write(output)
	return err
}

func runRuntimeSample(parent context.Context, command []string, input []byte, timeout time.Duration) ([]byte, error) {
	ctx, cancel := context.WithTimeout(parent, timeout)
	defer cancel()
	output := &runtimeSampleOutput{cancel: cancel}
	cmd := exec.CommandContext(ctx, command[0], command[1:]...)
	cmd.Stdin = bytes.NewReader(input)
	cmd.Stdout = output
	cmd.Stderr = io.Discard
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true, Pdeathsig: syscall.SIGKILL}
	cmd.Cancel = func() error {
		if cmd.Process == nil {
			return os.ErrProcessDone
		}
		return syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
	}
	cmd.WaitDelay = 100 * time.Millisecond
	// Keep the thread that owns Linux's parent-death signal alive through Wait.
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()
	err := cmd.Run()
	if cmd.Process != nil {
		_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
	}
	if err != nil || ctx.Err() != nil {
		return nil, errors.New("runtime command failed")
	}
	return output.buffer.Bytes(), nil
}
