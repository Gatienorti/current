//go:build linux

// Experimental fixed-size process supervisor. No queue protocol or PHP execution lives here.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"log"
	"os"
	"os/exec"
	"os/signal"
	"sync"
	"syscall"
	"time"
)

func worker(ctx context.Context, command []string, grace time.Duration, slot int) error {
	return managedWorker(ctx, command, grace, slot, "", 0, nil)
}

func managedWorker(ctx context.Context, command []string, grace time.Duration, slot int, directory string, maxStarts int, observe func(string, int, int)) (result error) {
	return managedWorkerIdentity(ctx, command, grace, slot, directory, maxStarts, func(state string, pid, starts int, identity *ProcessIdentity) {
		if observe != nil {
			observe(state, pid, starts)
		}
	})
}

func managedWorkerIdentity(ctx context.Context, command []string, grace time.Duration, slot int, directory string, maxStarts int, observe func(string, int, int, *ProcessIdentity)) (result error) {
	starts := 0
	var identity *ProcessIdentity
	report := func(state string, pid int) {
		if observe != nil {
			observe(state, pid, starts, identity)
		}
	}
	defer func() {
		if result != nil {
			report("failed", 0)
		} else {
			report("stopped", 0)
		}
	}()
	var recent []time.Time
	delay := 250 * time.Millisecond
	for {
		if ctx.Err() != nil {
			return nil
		}
		cutoff := time.Now().Add(-time.Minute)
		for len(recent) > 0 && recent[0].Before(cutoff) {
			recent = recent[1:]
		}
		if maxStarts > 0 && len(recent) >= maxStarts {
			return fmt.Errorf("slot %d: restart budget exhausted", slot)
		}
		// A fresh, private per-spawn identity lets manual cleanup reject reused PIDs.
		token, err := processToken()
		if err != nil {
			return err
		}
		started := time.Now()
		cmd, pidfd, err := startManagedProcess(command, directory, token)
		if err != nil {
			return fmt.Errorf("slot %d: %w", slot, err)
		}
		identity = nil
		if pidfd >= 0 {
			// The descriptor supplied at spawn must still describe a live child
			// after /proc is read. Bind the proof to the token we generated, never
			// adopt the identity of a process that has already reused its PID.
			proof, err := processIdentity(cmd.Process.Pid)
			exited, pollErr := processFDExited(pidfd)
			if err == nil && pollErr == nil && !exited && proof.Token == token {
				identity = proof
			}
			syscall.Close(pidfd)
		}
		starts++
		report("running", cmd.Process.Pid)
		log.Printf("slot=%d started pid=%d", slot, cmd.Process.Pid)
		done := make(chan error, 1)
		go func() { done <- cmd.Wait() }()
		healthyExit := false
		select {
		case err := <-done:
			var exit *exec.ExitError
			// Laravel's worker uses 12 for its deliberate memory-limit recycle.
			healthyExit = err == nil || (errors.As(err, &exit) && exit.ExitCode() == 12)
			if !healthyExit {
				recent = append(recent, time.Now())
			}
			// Reap any descendants left by an exited worker before restarting its slot.
			_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
			log.Printf("slot=%d exited: %v", slot, err)
		case <-ctx.Done():
			report("stopping", cmd.Process.Pid)
			_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGTERM)
			timer := time.NewTimer(grace)
			select {
			case <-done:
				if !timer.Stop() {
					<-timer.C
				}
			case <-timer.C:
				_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
				<-done
			}
			_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
			return nil
		}
		report("backoff", 0)
		if healthyExit || time.Since(started) >= 10*time.Second {
			delay = 250 * time.Millisecond
		}
		select {
		case <-ctx.Done():
			return nil
		case <-time.After(delay):
		}
		if !healthyExit && delay < 5*time.Second {
			delay *= 2
			if delay > 5*time.Second {
				delay = 5 * time.Second
			}
		}
	}
}

func startManagedProcess(command []string, directory, token string) (*exec.Cmd, int, error) {
	pidfd := -1
	build := func(proof bool) *exec.Cmd {
		cmd := exec.Command(command[0], command[1:]...)
		cmd.Env = append(os.Environ(), processTokenKey+"="+token)
		cmd.Dir = directory
		cmd.Stdout, cmd.Stderr = os.Stdout, os.Stderr
		cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true, Pdeathsig: syscall.SIGTERM}
		if proof {
			cmd.SysProcAttr.PidFD = &pidfd
		}
		return cmd
	}
	cmd := build(true)
	err := cmd.Start()
	if err != nil && (errors.Is(err, syscall.ENOSYS) || errors.Is(err, syscall.EINVAL) || errors.Is(err, syscall.EPERM) || errors.Is(err, syscall.EOPNOTSUPP)) {
		// Go preserves a caller-supplied PidFD even when its own capability
		// check fails. Retry the ordinary supported worker path, with no proof.
		// Start errors mean no successfully executing child was returned.
		pidfd = -1
		cmd = build(false)
		err = cmd.Start()
	}
	return cmd, pidfd, err
}

func supervise(ctx context.Context, command []string, workers int, grace time.Duration) error {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	var wg sync.WaitGroup
	errors := make(chan error, workers)
	for slot := 0; slot < workers; slot++ {
		wg.Add(1)
		go func(slot int) {
			defer wg.Done()
			if err := worker(ctx, command, grace, slot); err != nil {
				errors <- err
				cancel()
			}
		}(slot)
	}
	wg.Wait()
	close(errors)
	for err := range errors {
		return err
	}
	return nil
}

func watchParent(ctx context.Context, expected int, stop context.CancelFunc) {
	if expected == 0 {
		return
	}
	go func() {
		ticker := time.NewTicker(250 * time.Millisecond)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				if os.Getppid() != expected {
					stop()
					return
				}
			}
		}
	}()
}

func main() {
	if len(os.Args) > 1 && os.Args[1] == "-purge" {
		if err := purgeCLI(os.Args[2:]); err != nil {
			fmt.Fprintln(os.Stderr, err)
			os.Exit(1)
		}
		return
	}
	if len(os.Args) > 1 && os.Args[1] == "-runtime-sample" {
		if err := runtimeSampleCLI(os.Args[2:]); err != nil {
			fmt.Fprintln(os.Stderr, "runtime observation unavailable")
			os.Exit(1)
		}
		return
	}
	parent := flag.Int("parent-pid", 0, "drain if this launcher parent disappears")
	action := flag.String("control", "", "pause, resume, restart or stop an owned instance")
	pool := flag.String("pool", "", "pool name for pause/resume/restart")
	configPath := flag.String("config", "", "named-pool JSON configuration")
	status := flag.Bool("status", false, "read local health snapshot for -config")
	count := flag.Int("workers", 2, "fixed worker count (1-64)")
	grace := flag.Duration("grace", 15*time.Second, "graceful shutdown deadline")
	flag.Parse()
	if *parent < 0 || (*parent != 0 && os.Getppid() != *parent) {
		log.Fatal("launcher parent mismatch")
	}
	if *configPath != "" {
		mixed := false
		flag.Visit(func(f *flag.Flag) {
			if f.Name == "workers" || f.Name == "grace" {
				mixed = true
			}
		})
		if flag.NArg() != 0 || mixed {
			log.Fatal("-config cannot be combined with a worker command")
		}
		config, err := readConfig(*configPath)
		if err != nil {
			log.Fatal(err)
		}
		if *action != "" {
			if *status {
				log.Fatal("choose status or control")
			}
			data, err := readHealth(config, time.Now())
			if err != nil {
				log.Fatal(err)
			}
			var health Health
			if err := json.Unmarshal(data, &health); err != nil {
				log.Fatal(err)
			}
			if health.State == "stale" || health.State == "stopped" || health.State == "failed" {
				log.Fatal("supervisor is not available for control")
			}
			response, err := sendControl(config, health.Owner, *action, *pool)
			if err != nil {
				log.Fatal(err)
			}
			encoded, _ := json.Marshal(response)
			fmt.Println(string(encoded))
			return
		}
		if *pool != "" {
			log.Fatal("-pool requires -control")
		}
		if *status {
			snapshot, err := readHealth(config, time.Now())
			if err != nil {
				log.Fatal(err)
			}
			fmt.Println(string(snapshot))
			return
		}
		ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
		defer stop()
		watchParent(ctx, *parent, stop)
		if err := runConfig(ctx, config); err != nil {
			log.Fatal(err)
		}
		return
	}
	if *status || *action != "" || *pool != "" {
		log.Fatal("-status requires -config")
	}
	if *count < 1 || *count > 64 || *grace <= 0 || flag.NArg() == 0 {
		fmt.Fprintln(os.Stderr, "usage: supervisor -workers 2 -grace 15s -- php artisan queue:work ...")
		os.Exit(2)
	}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()
	watchParent(ctx, *parent, stop)
	if err := supervise(ctx, flag.Args(), *count, *grace); err != nil {
		log.Print(err)
		os.Exit(1)
	}
}
