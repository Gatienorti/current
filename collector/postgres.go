package main

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"fmt"
	"net"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/stdlib"
)

// The same bounded positive bigint is used by PHP. Namespaces separate collector,
// dispatch-cycle and maintenance locks. A hash collision only serializes work.
func advisoryKey(name string) int64 {
	sum := sha256.Sum256([]byte(name))
	key, _ := strconv.ParseInt(fmt.Sprintf("%x", sum)[:15], 16, 64)
	return key
}

type deadlineConn struct{ net.Conn }

func (c deadlineConn) Read(p []byte) (int, error) {
	c.SetReadDeadline(time.Now().Add(5 * time.Second))
	return c.Conn.Read(p)
}
func (c deadlineConn) Write(p []byte) (int, error) {
	c.SetWriteDeadline(time.Now().Add(5 * time.Second))
	return c.Conn.Write(p)
}

func openPostgres(s SQLSettings) (*sql.DB, error) {
	u := &url.URL{Scheme: "postgres", Host: net.JoinHostPort(s.Host, fmt.Sprint(s.Port)), Path: "/" + s.Database, User: url.UserPassword(s.Username, s.Password)}
	u.RawQuery = "sslmode=disable"
	cfg, err := pgx.ParseConfig(u.String())
	if err != nil {
		return nil, fmt.Errorf("invalid PostgreSQL settings")
	}
	// Do not inherit libpq environment runtime settings or plaintext fallbacks.
	cfg.Fallbacks = nil
	cfg.Host, cfg.User, cfg.Password, cfg.Database = s.Host, s.Username, s.Password, s.Database
	cfg.ValidateConnect = nil
	cfg.TLSConfig = nil
	cfg.RuntimeParams = map[string]string{"search_path": "public", "client_encoding": "UTF8", "application_name": "current-collector", "statement_timeout": "5000", "lock_timeout": "1000"}
	cfg.ConnectTimeout = 2 * time.Second
	cfg.StatementCacheCapacity = 64
	cfg.DialFunc = func(ctx context.Context, network, address string) (net.Conn, error) {
		c, e := (&net.Dialer{Timeout: 2 * time.Second}).DialContext(ctx, network, address)
		if e != nil {
			return nil, e
		}
		return deadlineConn{c}, nil
	}
	if s.TLS.Enabled {
		cfg.TLSConfig, err = databaseTLS(s.TLS, s.Host)
		if err != nil {
			return nil, err
		}
	}
	return stdlib.OpenDB(*cfg), nil
}

// Only package-owned SQL templates pass here, never payloads or user SQL.
// Preserve quoted literals while rebinding placeholders and identifier quotes.
func bindSQL(query string) string {
	if !postgresMode {
		return query
	}
	var out strings.Builder
	quoted := false
	n := 0
	for i := 0; i < len(query); i++ {
		c := query[i]
		if c == '\'' {
			out.WriteByte(c)
			if quoted && i+1 < len(query) && query[i+1] == '\'' {
				i++
				out.WriteByte('\'')
				continue
			}
			quoted = !quoted
			continue
		}
		if !quoted && c == '?' {
			n++
			out.WriteString("$" + strconv.Itoa(n))
		} else if !quoted && c == '`' {
			out.WriteByte('"')
		} else {
			out.WriteByte(c)
		}
	}
	return out.String()
}
func execSQL(tx *sql.Tx, q string, args ...interface{}) (sql.Result, error) {
	return tx.Exec(bindSQL(q), args...)
}
func querySQL(tx *sql.Tx, q string, args ...interface{}) (*sql.Rows, error) {
	return tx.Query(bindSQL(q), args...)
}
func queryRowSQL(tx *sql.Tx, q string, args ...interface{}) *sql.Row {
	return tx.QueryRow(bindSQL(q), args...)
}
