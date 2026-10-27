//go:build linux

package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"sync"
	"time"
)

type controlRequest struct {
	Owner   string `json:"owner"`
	Action  string `json:"action"`
	Pool    string `json:"pool"`
	Workers *int   `json:"workers,omitempty"`
}
type controlResponse struct {
	Accepted bool   `json:"accepted"`
	Error    string `json:"error,omitempty"`
}
type poolControl struct {
	mu         sync.Mutex
	paused     bool
	target     int
	generation int
	wake       chan struct{}
}

func newPoolControl(target int) *poolControl {
	return &poolControl{target: target, wake: make(chan struct{}, 1)}
}
func (p *poolControl) notify() {
	select {
	case p.wake <- struct{}{}:
	default:
	}
}
func (p *poolControl) setPaused(paused bool) {
	p.mu.Lock()
	p.paused = paused
	p.mu.Unlock()
	p.notify()
}
func (p *poolControl) restart() error {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.paused {
		return errors.New("pool is paused")
	}
	p.generation++
	p.notify()
	return nil
}
func (p *poolControl) setTarget(target int) {
	p.mu.Lock()
	p.target = target
	p.mu.Unlock()
	p.notify()
}
func (p *poolControl) desired() (int, bool, int) {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.target, p.paused, p.generation
}
func controlPath(c Config) string { return filepath.Join(c.StateDirectory, c.Instance+".sock") }
func listenControl(c Config, ctx context.Context, handle func(controlRequest) error) (func(), error) {
	path := controlPath(c)
	if len(path) > 103 {
		return nil, errors.New("control socket path exceeds local Unix socket limit")
	}
	if info, err := os.Lstat(path); err == nil {
		if info.Mode()&os.ModeSocket == 0 {
			return nil, errors.New("control path is not a socket")
		}
		if err := os.Remove(path); err != nil {
			return nil, err
		}
	} else if !os.IsNotExist(err) {
		return nil, err
	}
	listener, err := net.ListenUnix("unix", &net.UnixAddr{Name: path, Net: "unix"})
	if err != nil {
		return nil, err
	}
	if err := os.Chmod(path, 0600); err != nil {
		listener.Close()
		return nil, err
	}
	done := make(chan struct{})
	go func() {
		defer close(done)
		for {
			conn, err := listener.AcceptUnix()
			if err != nil {
				return
			}
			conn.SetDeadline(time.Now().Add(time.Second))
			line, readErr := bufio.NewReader(io.LimitReader(conn, 4097)).ReadBytes('\n')
			response := controlResponse{}
			if readErr != nil || len(line) > 4096 {
				response.Error = "invalid request"
			} else {
				d := json.NewDecoder(bytes.NewReader(line))
				d.DisallowUnknownFields()
				var request controlRequest
				if err := d.Decode(&request); err != nil {
					response.Error = "invalid request"
				} else {
					var extra any
					if d.Decode(&extra) != io.EOF {
						response.Error = "invalid request"
					} else if err := handle(request); err != nil {
						response.Error = err.Error()
					} else {
						response.Accepted = true
					}
				}
			}
			json.NewEncoder(conn).Encode(response)
			conn.Close()
		}
	}()
	closeOnce := sync.Once{}
	closeFn := func() { closeOnce.Do(func() { listener.Close(); <-done }) }
	go func() {
		select {
		case <-ctx.Done():
			closeFn()
		case <-done:
		}
	}()
	return closeFn, nil
}
func sendControl(c Config, owner, action, pool string) (controlResponse, error) {
	var response controlResponse
	conn, err := net.DialTimeout("unix", controlPath(c), time.Second)
	if err != nil {
		return response, err
	}
	defer conn.Close()
	conn.SetDeadline(time.Now().Add(time.Second))
	if err := json.NewEncoder(conn).Encode(controlRequest{Owner: owner, Action: action, Pool: pool}); err != nil {
		return response, err
	}
	if err := json.NewDecoder(io.LimitReader(conn, 4096)).Decode(&response); err != nil {
		return response, err
	}
	if !response.Accepted {
		return response, fmt.Errorf("control rejected: %s", response.Error)
	}
	return response, nil
}
