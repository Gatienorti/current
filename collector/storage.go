package main

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"errors"
	"fmt"
	"net"
	"os"
	"syscall"
	"time"

	"github.com/go-sql-driver/mysql"
)

// One process uses one SQL dialect and a pinned connection. A lost MySQL
// connection cannot reconnect silently and continue without its ownership lock.
var mysqlMode bool

type SQLSettings struct {
	TLS      SQLTLS `json:"tls"`
	Driver   string `json:"driver"`
	Host     string `json:"host"`
	Port     int    `json:"port"`
	Socket   string `json:"socket"`
	Database string `json:"database"`
	Username string `json:"username"`
	Password string `json:"password"`
}

type storage struct {
	db   *sql.DB
	conn *sql.Conn
	lock *os.File
}

func (s *storage) Query(q string, args ...interface{}) (*sql.Rows, error) {
	return s.conn.QueryContext(context.Background(), q, args...)
}
func (s *storage) Exec(q string, args ...interface{}) (sql.Result, error) {
	return s.conn.ExecContext(context.Background(), q, args...)
}
func (s *storage) Begin() (*sql.Tx, error) { return s.conn.BeginTx(context.Background(), nil) }
func (s *storage) Close() {
	if s.conn != nil {
		s.conn.Close()
	}
	if s.db != nil {
		s.db.Close()
	}
	if s.lock != nil {
		syscall.Flock(int(s.lock.Fd()), syscall.LOCK_UN)
		s.lock.Close()
	}
}
func openStorage(s Settings) (_ *storage, err error) {
	out := &storage{}
	defer func() {
		if err != nil {
			out.Close()
		}
	}()
	mysqlMode = s.SQL.Driver == "mysql" || s.SQL.Driver == "mariadb"
	driver, dsn := "sqlite3", s.File+"?_journal_mode=WAL&_synchronous=FULL&_busy_timeout=250"
	if mysqlMode {
		cfg := mysql.NewConfig()
		cfg.User, cfg.Passwd, cfg.DBName = s.SQL.Username, s.SQL.Password, s.SQL.Database
		cfg.Net, cfg.Addr = "tcp", net.JoinHostPort(s.SQL.Host, fmt.Sprint(s.SQL.Port))
		if s.SQL.Socket != "" {
			cfg.Net, cfg.Addr = "unix", s.SQL.Socket
		}
		if s.SQL.TLS.Enabled {
			tlsConfig, e := databaseTLS(s.SQL.TLS, s.SQL.Host)
			if e != nil {
				return nil, e
			}
			if mysql.RegisterTLSConfig("current", tlsConfig) != nil {
				return nil, errors.New("SQL TLS configuration invalid")
			}
			cfg.TLSConfig = "current"
		}
		cfg.Timeout, cfg.ReadTimeout, cfg.WriteTimeout = 2*time.Second, 5*time.Second, 5*time.Second
		cfg.Params = map[string]string{"charset": "utf8mb4", "innodb_lock_wait_timeout": "1", "sql_mode": "'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'"}
		driver, dsn = "mysql", cfg.FormatDSN()
	} else {
		if s.SQL.Driver != "" && s.SQL.Driver != "sqlite" {
			return nil, errors.New("unsupported database driver")
		}
		info, e := os.Stat(s.File)
		if e != nil || !info.Mode().IsRegular() {
			return nil, errors.New("SQLite file missing; run migrations first")
		}
		out.lock, err = os.OpenFile(s.File+".collector.lock", os.O_CREATE|os.O_RDWR, 0600)
		if err != nil {
			return nil, errors.New("collector lock unavailable")
		}
		if syscall.Flock(int(out.lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB) != nil {
			return nil, errors.New("a collector already owns this database")
		}
	}
	out.db, err = sql.Open(driver, dsn)
	if err != nil {
		return nil, errors.New("database unavailable")
	}
	out.db.SetMaxOpenConns(1)
	out.conn, err = out.db.Conn(context.Background())
	if err != nil {
		return nil, errors.New("database unavailable")
	}
	if mysqlMode {
		var name string
		if out.conn.QueryRowContext(context.Background(), "SELECT DATABASE()").Scan(&name) != nil || name == "" {
			return nil, errors.New("database unavailable")
		}
		key := fmt.Sprintf("current:%x", sha256.Sum256([]byte(name)))[:63]
		var owned int
		if out.conn.QueryRowContext(context.Background(), "SELECT GET_LOCK(?,0)", key).Scan(&owned) != nil || owned != 1 {
			return nil, errors.New("a collector already owns this database")
		}
		for _, t := range []string{"lens_jobs", "lens_attempts", "lens_steps", "lens_tags", "lens_meta", "lens_async_receipts", "lens_async_versions"} {
			var engine string
			if out.conn.QueryRowContext(context.Background(), "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?", t).Scan(&engine) != nil || engine != "InnoDB" {
				return nil, errors.New("Current tables must exist and use InnoDB; run migrations")
			}
		}
	}
	return out, nil
}
func sqlFor(sqlite, mysql string) string {
	if mysqlMode {
		return mysql
	}
	return sqlite
}
func pruneSQL(t string) string {
	if mysqlMode {
		return "DELETE FROM " + t + " WHERE expires_at <= ? LIMIT 500"
	}
	return "DELETE FROM " + t + " WHERE rowid IN (SELECT rowid FROM " + t + " WHERE expires_at <= ? LIMIT 500)"
}
