package main

import (
	"database/sql"
	"fmt"
	"math"
	"os"
	"testing"
	"time"

	"github.com/go-sql-driver/mysql"
)

// The server is explicitly disposable; this test creates and drops only its
// own uniquely named database, without touching a host application's schema.
func TestMonitoredVersionFenceExtensionOnServerSQL(t *testing.T) {
	host := os.Getenv("CURRENT_TEST_SQL_HOST")
	if host == "" {
		t.Skip("requires a disposable MySQL/MariaDB server")
	}
	cfg := mysql.NewConfig()
	cfg.User, cfg.Passwd, cfg.Net, cfg.Addr = "root", os.Getenv("CURRENT_TEST_SQL_PASSWORD"), "tcp", host+":3306"
	cfg.Timeout, cfg.ReadTimeout, cfg.WriteTimeout = time.Second, 5*time.Second, time.Second
	admin, err := sql.Open("mysql", cfg.FormatDSN())
	if err != nil {
		t.Fatal(err)
	}
	defer admin.Close()
	name := fmt.Sprintf("current_monitored_%d", time.Now().UnixNano())
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
	for _, ddl := range []string{
		"CREATE TABLE lens_jobs(id CHAR(26) PRIMARY KEY,external_id VARCHAR(100),connection VARCHAR(100),dispatch_id CHAR(26),first_observed_at DOUBLE,expires_at DOUBLE) ENGINE=InnoDB",
		"CREATE TABLE lens_async_aliases(id CHAR(26) PRIMARY KEY,canonical_id CHAR(26),identity_hash CHAR(64),first_observed_at DOUBLE,job_expires_at DOUBLE,expires_at DOUBLE) ENGINE=InnoDB",
		"CREATE TABLE lens_attempts(id CHAR(26) PRIMARY KEY,job_id CHAR(26),status VARCHAR(32)) ENGINE=InnoDB",
		"CREATE TABLE lens_steps(id CHAR(26) PRIMARY KEY,attempt_id CHAR(26),status VARCHAR(32)) ENGINE=InnoDB",
		"CREATE TABLE lens_async_versions(entity VARCHAR(100) PRIMARY KEY,revision INT,expires_at DOUBLE) ENGINE=InnoDB",
	} {
		if _, err = db.Exec(ddl); err != nil {
			t.Fatal(err)
		}
	}
	v := identityEvent(731)
	id := v.Job["id"].(string)
	oldExpiry := v.Job["expires_at"].(float64)
	if _, err = db.Exec("INSERT INTO lens_jobs VALUES(?,?,?,?,?,?)", id, "uuid", "redis", "", v.Observed, oldExpiry); err != nil {
		t.Fatal(err)
	}
	for _, child := range []struct{ table, id, parent string }{
		{"lens_attempts", "01BBBBBBBBBBBBBBBBBBBBBBBB", id},
		{"lens_steps", "01CCCCCCCCCCCCCCCCCCCCCCCC", "01BBBBBBBBBBBBBBBBBBBBBBBB"},
	} {
		if _, err = db.Exec("INSERT INTO "+child.table+" VALUES(?,?,?)", child.id, child.parent, "completed"); err != nil {
			t.Fatal(err)
		}
		if _, err = db.Exec("INSERT INTO lens_async_versions VALUES(?,?,?)", child.table+":"+child.id, 3, oldExpiry+86400); err != nil {
			t.Fatal(err)
		}
	}
	oldMode := mysqlMode
	mysqlMode = true
	defer func() { mysqlMode = oldMode }()
	v.MonitoredExpires = v.Observed + 7*86400
	tx, err := db.Begin()
	if err != nil {
		t.Fatal(err)
	}
	defer tx.Rollback()
	if _, err = canonicalIdentity(tx, v); err != nil {
		t.Fatal(err)
	}
	if err = tx.Commit(); err != nil {
		t.Fatal(err)
	}
	var expiry float64
	if err = db.QueryRow("SELECT MIN(expires_at) FROM lens_async_versions").Scan(&expiry); err != nil || expiry != v.MonitoredExpires+86400 {
		t.Fatal("server SQL did not retain all revision fences", expiry, err)
	}
	if _, err = db.Exec("DELETE FROM lens_async_versions WHERE expires_at<=?", oldExpiry+86400+1); err != nil {
		t.Fatal(err)
	}
	tx, err = db.Begin()
	if err != nil {
		t.Fatal(err)
	}
	defer tx.Rollback()
	if err = versioned(tx, "lens_steps", map[string]interface{}{"id": "01CCCCCCCCCCCCCCCCCCCCCCCC", "status": "running"}, 1, v.MonitoredExpires+86400); err != nil {
		t.Fatal(err)
	}
	if err = tx.Commit(); err != nil {
		t.Fatal(err)
	}
	var status string
	if err = db.QueryRow("SELECT status FROM lens_steps").Scan(&status); err != nil || status != "completed" {
		t.Fatal("server replay regressed retained step", status, err)
	}
}

func TestMonitoredExtensionPreservesEarlierRevisionFencesAfterOrdinaryPruning(t *testing.T) {
	db := fixture(t)
	done := identityEvent(721)
	done.Revision = 3
	done.Attempt = map[string]interface{}{"id": "01BBBBBBBBBBBBBBBBBBBBBBBB", "job_id": done.Job["id"], "worker_id": "worker", "reported_attempt": float64(1), "status": "completed", "started_at": done.Observed, "finished_at": done.Observed + 1}
	done.StepRevision = 2
	done.Step = map[string]interface{}{"id": "01CCCCCCCCCCCCCCCCCCCCCCCC", "attempt_id": done.Attempt["id"], "parent_id": nil, "sequence": float64(1), "label": "Earlier work", "status": "completed", "started_at": done.Observed, "finished_at": done.Observed + 1}
	apply(t, db, done)
	originalExpiry := done.Job["expires_at"].(float64)
	// A later attempt can extend retention without replaying any earlier child.
	extension := identityEvent(722)
	extension.MonitoredExpires = extension.Observed + 7*86400
	apply(t, db, extension)
	var earliest float64
	if err := db.QueryRow("SELECT MIN(expires_at) FROM lens_async_versions").Scan(&earliest); err != nil || earliest != extension.MonitoredExpires+86400 {
		t.Fatal("earlier child revision fence not extended", earliest, err)
	}
	// Simulate pruning after ordinary retention and receipt expiry have elapsed.
	if _, err := db.Exec("DELETE FROM lens_async_versions WHERE expires_at<=?", originalExpiry+86400+1); err != nil {
		t.Fatal(err)
	}
	db.Exec("DELETE FROM lens_async_receipts")
	done.ID = fmt.Sprintf("%026d", 723)
	done.Revision = 1
	done.StepRevision = 1
	done.Attempt["status"], done.Step["status"] = "processing", "running"
	delete(done.Attempt, "finished_at")
	delete(done.Step, "finished_at")
	apply(t, db, done)
	for _, table := range []string{"lens_jobs", "lens_attempts", "lens_steps"} {
		var status string
		if err := db.QueryRow("SELECT status FROM " + table).Scan(&status); err != nil || status != "completed" {
			t.Fatal("late snapshot regressed retained history", table, status, err)
		}
	}
}

func TestMonitoredCompletionExtendsCanonicalDetailAndEveryAliasWithoutSlidingOnOrdinaryEvents(t *testing.T) {
	db := fixture(t)
	original := identityEvent(701)
	original.Job["dispatch_id"] = "dispatch-monitored"
	apply(t, db, original)
	other := identityEvent(702)
	other.Job["dispatch_id"] = "dispatch-monitored"
	other.Job["id"] = "01BBBBBBBBBBBBBBBBBBBBBBBB"
	apply(t, db, other)
	completed := identityEvent(703)
	completed.Job["dispatch_id"] = "dispatch-monitored"
	completed.Job["id"] = "01CCCCCCCCCCCCCCCCCCCCCCCC"
	completed.MonitoredExpires = completed.Observed + 7*86400
	apply(t, db, completed)
	var expiry, smallest, largest float64
	db.QueryRow("SELECT expires_at FROM lens_jobs").Scan(&expiry)
	if expiry != completed.MonitoredExpires {
		t.Fatal("monitored detail expiry not extended", expiry)
	}
	db.QueryRow("SELECT MIN(job_expires_at),MAX(job_expires_at) FROM lens_async_aliases").Scan(&smallest, &largest)
	if smallest != expiry || largest != expiry {
		t.Fatal("aliases disagree with monitored detail expiry", smallest, largest)
	}
	ordinary := identityEvent(704)
	ordinary.Job["id"] = other.Job["id"]
	ordinary.Job["dispatch_id"] = "dispatch-monitored"
	ordinary.Job["expires_at"] = expiry + 10000
	apply(t, db, ordinary)
	db.QueryRow("SELECT expires_at FROM lens_jobs").Scan(&largest)
	if largest != expiry {
		t.Fatal("ordinary event changed monitored retention", largest)
	}
	// Redis/producer replay must not revive a canonical identity after its bound.
	db.Exec("UPDATE lens_async_aliases SET job_expires_at=?", completed.Observed-1)
	completed.ID = "00000000000000000000000705"
	tx, _ := db.Begin()
	_, err := canonicalIdentity(tx, completed)
	tx.Rollback()
	if err != expiredIdentity {
		t.Fatal("expired monitored identity was revived", err)
	}
}

func TestMonitoredExpiryIsVersionedFiniteAndBounded(t *testing.T) {
	db := fixture(t)
	_ = db
	v := identityEvent(711)
	for _, expiry := range []float64{-1, v.Observed, v.Observed + 30*86400 + 1, math.Inf(1), math.NaN()} {
		v.MonitoredExpires = expiry
		if validate(v) == nil {
			t.Fatal("invalid monitored expiry accepted", expiry)
		}
	}
	v.MonitoredExpires = v.Observed + 86400
	if err := validate(v); err != nil {
		t.Fatal(err)
	}
	v.Version = 1
	if validate(v) == nil {
		t.Fatal("legacy protocol accepted monitored expiry")
	}
}
