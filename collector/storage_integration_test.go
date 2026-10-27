package main

import (
	"context"
	"database/sql"
	"fmt"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/go-sql-driver/mysql"
)

// Use only an explicitly supplied disposable SQL server with database-creation
// privileges. No test changes server-global isolation or application databases.
func TestSQLMaintenanceAbsenceLockSurvivesReadCommittedDefault(t *testing.T) {
	host := os.Getenv("CURRENT_TEST_SQL_HOST")
	if host == "" {
		t.Skip("requires a disposable MySQL/MariaDB server")
	}
	cfg := mysql.NewConfig()
	cfg.User, cfg.Passwd, cfg.Net, cfg.Addr = "root", os.Getenv("CURRENT_TEST_SQL_PASSWORD"), "tcp", host+":3306"
	cfg.Timeout, cfg.ReadTimeout, cfg.WriteTimeout = time.Second, 5*time.Second, time.Second
	if directory := os.Getenv("CURRENT_ASYNC_TLS_DIR"); directory != "" {
		tlsConfig, err := databaseTLS(SQLTLS{Enabled: true, CA: filepath.Join(directory, "ca.pem"), Cert: filepath.Join(directory, "client.pem"), Key: filepath.Join(directory, "client-key.pem")}, host)
		if err != nil {
			t.Fatal(err)
		}
		if err = mysql.RegisterTLSConfig("current-integration", tlsConfig); err != nil {
			t.Fatal(err)
		}
		defer mysql.DeregisterTLSConfig("current-integration")
		cfg.TLSConfig = "current-integration"
	}
	admin, err := sql.Open("mysql", cfg.FormatDSN())
	if err != nil {
		t.Fatal(err)
	}
	defer admin.Close()
	name := fmt.Sprintf("current_isolation_%d", time.Now().UnixNano())
	if _, err = admin.Exec("CREATE DATABASE `" + name + "`"); err != nil {
		t.Fatal(err)
	}
	defer admin.Exec("DROP DATABASE `" + name + "`")
	cfg.DBName = name
	db, err := sql.Open("mysql", cfg.FormatDSN())
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	if _, err = db.Exec("CREATE TABLE lens_job_actions (identity_hash CHAR(64) PRIMARY KEY, kind VARCHAR(16), job_id CHAR(26) NULL, observed_at DOUBLE, expires_at DOUBLE) ENGINE=InnoDB"); err != nil {
		t.Fatal(err)
	}
	conn, err := db.Conn(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	if _, err = conn.ExecContext(context.Background(), "SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED"); err != nil {
		t.Fatal(err)
	}
	oldMode := mysqlMode
	mysqlMode = true
	defer func() { mysqlMode = oldMode }()
	s := &storage{conn: conn}
	tx, err := s.Begin()
	if err != nil {
		t.Fatal(err)
	}
	defer tx.Rollback()
	event := Event{Job: map[string]interface{}{"external_id": "fixture-uuid", "connection": "redis", "dispatch_id": "fixture-dispatch"}}
	if action, err := jobAction(tx, event, map[string]*JobAction{}); err != nil || action != nil {
		t.Fatalf("absent marker: %v, %v", action, err)
	}
	inserted := make(chan error, 1)
	go func() {
		ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
		defer cancel()
		_, err := db.ExecContext(ctx, "INSERT INTO lens_job_actions(identity_hash,kind,observed_at,expires_at) VALUES(?,?,?,?)", actionFingerprint("fixture-uuid", "redis", "fixture-dispatch"), "forgotten", 1, 2)
		inserted <- err
	}()
	select {
	case err := <-inserted:
		t.Fatalf("maintenance insertion bypassed the in-flight absence lock: %v", err)
	case <-time.After(200 * time.Millisecond):
	}
	if err := tx.Commit(); err != nil {
		t.Fatal(err)
	}
	select {
	case err := <-inserted:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("maintenance did not resume after observation committed")
	}
	if _, err = db.Exec("CREATE TABLE lens_meta (`key` VARCHAR(60) PRIMARY KEY, value TEXT) ENGINE=InnoDB"); err != nil {
		t.Fatal(err)
	}
	if _, err = db.Exec("UPDATE lens_job_actions SET job_id=?", "01AAAAAAAAAAAAAAAAAAAAAAAA"); err != nil {
		t.Fatal(err)
	}
	if _, err = db.Exec("INSERT INTO lens_meta VALUES(?,?)", "retry:01AAAAAAAAAAAAAAAAAAAAAAAA", `{"state":"forgotten"}`); err != nil {
		t.Fatal(err)
	}
	if err = pruneMaintenance(s, 3); err != nil {
		t.Fatal(err)
	}
	var remaining int
	db.QueryRow("SELECT COUNT(*) FROM lens_meta").Scan(&remaining)
	if remaining != 0 {
		t.Fatal("expired maintenance stranded its retry claim")
	}
	db.QueryRow("SELECT COUNT(*) FROM lens_job_actions").Scan(&remaining)
	if remaining != 0 {
		t.Fatal("expired maintenance marker survived pruning")
	}

}
