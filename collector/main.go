// Current's single-collector SQL telemetry writer. Job execution stays in Laravel/Horizon.
package main

import (
	"context"
	"crypto/rand"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	_ "github.com/mattn/go-sqlite3"
	"github.com/redis/go-redis/v9"
	"io"
	"os"
	"os/signal"
	"runtime"
	"syscall"
	"time"
)

type Settings struct {
	SQL      SQLSettings `json:"sql"`
	Address  string      `json:"address"`
	Username string      `json:"username"`
	Password string      `json:"password"`
	Database int         `json:"database"`
	TLS      bool        `json:"tls"`
	Prefix   string      `json:"prefix"`
	File     string      `json:"file"`
	MaxAge   int         `json:"max_age"`
	MaxBytes int         `json:"max_bytes"`
	Once     bool        `json:"once"`
}

const renew = `if redis.call('GET',KEYS[1])==ARGV[1] then redis.call('EXPIRE',KEYS[1],30); return 1 end return 0`
const release = `if redis.call('GET',KEYS[1])==ARGV[1] then return redis.call('DEL',KEYS[1]) end return 0`
const acknowledge = `if redis.call('GET',KEYS[2])~=ARGV[1] then return -1 end local ids={}; for i=6,#ARGV do ids[#ids+1]=ARGV[i] end local n=redis.call('XACK',KEYS[1],'collector',unpack(ids)); redis.call('XDEL',KEYS[1],unpack(ids)); redis.call('HINCRBY',KEYS[3],'processed',n); redis.call('HINCRBY',KEYS[3],'invalid',ARGV[2]); redis.call('HINCRBY',KEYS[3],'expired',ARGV[3]); redis.call('HSET',KEYS[3],'last_commit_at',ARGV[4],'last_event_at',ARGV[5],'last_error',''); return n`

var version = "dev"

func main() {
	if len(os.Args) == 2 && os.Args[1] == "--version" {
		json.NewEncoder(os.Stdout).Encode(map[string]interface{}{"name": "current-collector", "version": version, "protocol": 1, "os": runtime.GOOS, "arch": runtime.GOARCH})
		return
	}
	if e := run(); e != nil {
		fmt.Fprintln(os.Stderr, e)
		os.Exit(1)
	}
}
func run() error {
	if len(os.Args) != 2 {
		return errors.New("usage: current-collector <private-config-file>")
	}
	var f *os.File
	var e error
	if os.Args[1] == "-" {
		f = os.Stdin
	} else {
		f, e = os.Open(os.Args[1])
	}
	if e != nil {
		return errors.New("configuration unavailable")
	}
	var s Settings
	e = json.NewDecoder(io.LimitReader(f, 65536)).Decode(&s)
	f.Close()
	if e != nil || s.Prefix == "" || s.Address == "" || s.MaxAge < 60 || s.MaxAge > 86400 || s.MaxBytes < 4096 || s.MaxBytes > 262144 {
		return errors.New("invalid collector configuration")
	}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()
	opts := &redis.Options{Addr: s.Address, Username: s.Username, Password: s.Password, DB: s.Database, DialTimeout: time.Second, ReadTimeout: time.Second, WriteTimeout: time.Second, MaxRetries: -1}
	if s.TLS {
		opts.TLSConfig = &tls.Config{MinVersion: tls.VersionTLS12}
	}
	r := redis.NewClient(opts)
	defer r.Close()
	stream, health, owner := s.Prefix+"events", s.Prefix+"health", s.Prefix+"owner"
	var secret [16]byte
	if _, e = rand.Read(secret[:]); e != nil {
		return errors.New("ownership token unavailable")
	}
	token := hex.EncodeToString(secret[:])
	acquired, e := r.SetNX(ctx, owner, token, 30*time.Second).Result()
	if e != nil || !acquired {
		return errors.New("Redis unavailable or collector namespace already owned")
	}
	defer r.Eval(context.Background(), release, []string{owner}, token)
	db, e := openStorage(s)
	if e != nil {
		return e
	}
	defer db.Close()
	if e = loadColumns(db); e != nil {
		return errors.New("Current tables unavailable; run migrations")
	}
	for _, t := range []string{"lens_async_receipts", "lens_async_versions"} {
		if _, e = db.Exec("SELECT 1 FROM " + t + " LIMIT 1"); e != nil {
			return errors.New("async tables unavailable; run migrations")
		}
	}
	parent := os.Getppid()
	nextHeartbeat := time.Time{}
	nextPrune := time.Time{}
	fmt.Println("Current telemetry collector started")
	for ctx.Err() == nil {
		if parent > 1 && os.Getppid() != parent {
			return errors.New("collector launcher exited")
		}
		if time.Now().After(nextHeartbeat) {
			ok, err := r.Eval(ctx, renew, []string{owner}, token).Int()
			if err != nil || ok != 1 {
				return errors.New("collector ownership lost; restart after Redis recovers")
			}
			r.HSet(ctx, health, "heartbeat_at", float64(time.Now().UnixMilli())/1000)
			nextHeartbeat = time.Now().Add(3 * time.Second)
		}
		if time.Now().After(nextPrune) {
			for _, t := range []string{"lens_async_receipts", "lens_async_versions"} {
				db.Exec(pruneSQL(t), float64(time.Now().Unix()))
			}
			nextPrune = time.Now().Add(time.Minute)
		}
		// Stream expiry/server restart may remove the group; MKSTREAM/BUSYGROUP are safe.
		if e = r.XGroupCreateMkStream(ctx, stream, "collector", "0").Err(); e != nil && !isBusyGroup(e) {
			return errors.New("Redis consumer group unavailable")
		}
		msgs, _, err := r.XAutoClaim(ctx, &redis.XAutoClaimArgs{Stream: stream, Group: "collector", Consumer: token, MinIdle: 0, Start: "0-0", Count: 100}).Result()
		if err != nil {
			return errors.New("Redis pending read failed")
		}
		if len(msgs) == 0 {
			blocks, err := r.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "collector", Consumer: token, Streams: []string{stream, ">"}, Count: 100, Block: -1}).Result()
			if err != nil && err != redis.Nil {
				return errors.New("Redis stream read failed")
			}
			if len(blocks) > 0 {
				msgs = blocks[0].Messages
			}
		}
		if len(msgs) == 0 {
			if s.Once {
				break
			}
			select {
			case <-ctx.Done():
			case <-time.After(100 * time.Millisecond):
			}
			continue
		}
		tx, err := db.Begin()
		if err != nil {
			r.HSet(ctx, health, "last_error", "database_unavailable")
			if mysqlMode && db.conn.PingContext(ctx) != nil {
				return errors.New("database connection lost; restart to reacquire ownership")
			}
			if s.Once {
				return errors.New("database unavailable; events remain pending")
			}
			pause(ctx)
			continue
		}
		var ids []interface{}
		var newest float64
		bad, expired := 0, 0
		for _, m := range msgs {
			raw, ok := m.Values["data"].(string)
			var v Event
			if !ok || len(raw) > s.MaxBytes || json.Unmarshal([]byte(raw), &v) != nil || validate(v) != nil {
				bad++
				ids = append(ids, m.ID)
				continue
			}
			now := float64(time.Now().UnixMilli()) / 1000
			if now-v.Observed >= float64(s.MaxAge) || v.Job["expires_at"].(float64) <= now {
				expired++
				ids = append(ids, m.ID)
				continue
			}
			if err = project(tx, v); err != nil {
				break
			}
			ids = append(ids, m.ID)
			if v.Observed > newest {
				newest = v.Observed
			}
		}
		if err != nil {
			tx.Rollback()
			r.HSet(ctx, health, "last_error", "database_write_failed")
			if s.Once {
				return errors.New("database write failed; events remain pending")
			}
			pause(ctx)
			continue
		}
		if err = tx.Commit(); err != nil {
			r.HSet(ctx, health, "last_error", "database_commit_failed")
			if s.Once {
				return errors.New("database commit failed; events remain pending")
			}
			pause(ctx)
			continue
		}
		args := append([]interface{}{token, bad, expired, float64(time.Now().UnixMilli()) / 1000, newest}, ids...)
		n, err := r.Eval(ctx, acknowledge, []string{stream, owner, health}, args...).Int()
		if err != nil || n < 0 {
			return errors.New("acknowledgement failed; committed events may replay")
		}

	}
	fmt.Println("Current telemetry collector stopped")
	return nil
}
func pause(ctx context.Context) {
	select {
	case <-ctx.Done():
	case <-time.After(time.Second):
	}
}
func isBusyGroup(e error) bool { return len(e.Error()) >= 9 && e.Error()[:9] == "BUSYGROUP" }
