package main

import (
	"database/sql"
	"fmt"
	"path/filepath"
	"testing"
	"time"
)

func fixture(t *testing.T) *sql.DB {
	t.Helper()
	db, e := sql.Open("sqlite3", filepath.Join(t.TempDir(), "current.sqlite"))
	if e != nil {
		t.Fatal(e)
	}
	t.Cleanup(func() { db.Close() })
	schema := `CREATE TABLE lens_jobs(id TEXT PRIMARY KEY,external_id TEXT,connection TEXT,queue TEXT,job_class TEXT,status TEXT,coverage TEXT,first_observed_at REAL,observed_at REAL,expires_at REAL,attempt_count INTEGER DEFAULT 0,exception_class TEXT,silenced INTEGER,dispatch_id TEXT,cleared_at REAL);
 CREATE TABLE lens_attempts(id TEXT PRIMARY KEY,job_id TEXT,worker_id TEXT,reported_attempt INTEGER,status TEXT,started_at REAL,finished_at REAL,exception_class TEXT);
 CREATE TABLE lens_steps(id TEXT PRIMARY KEY,attempt_id TEXT,parent_id TEXT,sequence INTEGER,label TEXT,status TEXT,started_at REAL,finished_at REAL);
 CREATE TABLE lens_tags(job_id TEXT,tag TEXT,PRIMARY KEY(job_id,tag));CREATE TABLE lens_meta(key TEXT PRIMARY KEY,value TEXT);
 CREATE TABLE lens_job_actions(identity_hash TEXT PRIMARY KEY,kind TEXT,job_id TEXT,observed_at REAL,expires_at REAL);CREATE TABLE lens_async_aliases(id TEXT PRIMARY KEY,canonical_id TEXT,identity_hash TEXT,first_observed_at REAL,job_expires_at REAL,expires_at REAL);CREATE TABLE lens_webhook_deliveries(id TEXT PRIMARY KEY,job_id TEXT);CREATE TABLE lens_broadcasts(id TEXT PRIMARY KEY,job_id TEXT);CREATE TABLE lens_async_receipts(id TEXT PRIMARY KEY,expires_at REAL);CREATE TABLE lens_async_versions(entity TEXT PRIMARY KEY,revision INTEGER,expires_at REAL);`
	if _, e = db.Exec(schema); e != nil {
		t.Fatal(e)
	}
	if e = loadColumns(db); e != nil {
		t.Fatal(e)
	}
	return db
}
func event(n int) Event {
	now := float64(time.Now().Unix())
	return Event{Version: 1, ID: fmt.Sprintf("%026d", n), Observed: now, Job: map[string]interface{}{"id": "01AAAAAAAAAAAAAAAAAAAAAAAA", "external_id": "uuid", "connection": "redis", "queue": "test", "job_class": "Synthetic", "status": "pending", "coverage": "start_only", "first_observed_at": now, "observed_at": now, "expires_at": now + 3600}}
}
func apply(t *testing.T, db *sql.DB, v Event) {
	t.Helper()
	if e := validate(v); e != nil {
		t.Fatal(e)
	}
	tx, e := db.Begin()
	if e != nil {
		t.Fatal(e)
	}
	if e = project(tx, v); e != nil {
		tx.Rollback()
		t.Fatal(e)
	}
	if e = tx.Commit(); e != nil {
		t.Fatal(e)
	}
}
func TestOutOfOrderSnapshotsCannotRegressFinishedAttemptsOrSteps(t *testing.T) {
	db := fixture(t)
	finish := event(1)
	finish.Revision = 3
	finish.Attempt = map[string]interface{}{"id": "01BBBBBBBBBBBBBBBBBBBBBBBB", "job_id": finish.Job["id"], "worker_id": "worker", "reported_attempt": float64(1), "status": "completed", "started_at": finish.Observed, "finished_at": finish.Observed + 1}
	finish.StepRevision = 2
	finish.Step = map[string]interface{}{"id": "01CCCCCCCCCCCCCCCCCCCCCCCC", "attempt_id": finish.Attempt["id"], "parent_id": nil, "sequence": float64(1), "label": "Work", "status": "completed", "started_at": finish.Observed, "finished_at": finish.Observed + 1}
	apply(t, db, finish)
	early := event(2)
	early.Revision = 1
	early.Attempt = map[string]interface{}{}
	for k, v := range finish.Attempt {
		early.Attempt[k] = v
	}
	early.Attempt["status"] = "processing"
	delete(early.Attempt, "finished_at")
	early.StepRevision = 1
	early.Step = map[string]interface{}{}
	for k, v := range finish.Step {
		early.Step[k] = v
	}
	early.Step["status"] = "running"
	delete(early.Step, "finished_at")
	apply(t, db, early)
	enqueue := event(3)
	enqueue.Enqueue = map[string]interface{}{}
	for k, v := range enqueue.Job {
		enqueue.Enqueue[k] = v
	}
	enqueue.Enqueue["coverage"] = "observed_enqueue"
	apply(t, db, enqueue)
	for _, table := range []string{"lens_jobs", "lens_attempts", "lens_steps"} {
		var status string
		if e := db.QueryRow("SELECT status FROM " + table).Scan(&status); e != nil || status != "completed" {
			t.Fatalf("%s: %s %v", table, status, e)
		}
	}
	var count int
	db.QueryRow("SELECT attempt_count FROM lens_jobs").Scan(&count)
	if count != 1 {
		t.Fatal(count)
	}
}
func TestRollbackAndPostCommitReplay(t *testing.T) {
	db := fixture(t)
	v := event(1)
	tx, _ := db.Begin()
	if e := project(tx, v); e != nil {
		t.Fatal(e)
	}
	tx.Rollback()
	var count int
	db.QueryRow("SELECT COUNT(*) FROM lens_async_receipts").Scan(&count)
	if count != 0 {
		t.Fatal("receipt survived rollback")
	}
	apply(t, db, v)
	apply(t, db, v)
	db.QueryRow("SELECT COUNT(*) FROM lens_async_receipts").Scan(&count)
	if count != 1 {
		t.Fatal("duplicate receipt")
	}
	db.QueryRow("SELECT COUNT(*) FROM lens_jobs").Scan(&count)
	if count != 1 {
		t.Fatal("duplicate job")
	}
}
func TestInvalidRelationshipsAndUnknownColumnsAreRejected(t *testing.T) {
	fixture(t)
	v := event(1)
	v.Job["raw_secret"] = "not permitted"
	if validate(v) == nil {
		t.Fatal("unknown column accepted")
	}
	delete(v.Job, "raw_secret")
	v.Version = 99
	if validate(v) == nil {
		t.Fatal("unknown version accepted")
	}
	v.Version = 1
	v.Enqueue = map[string]interface{}{"id": "01DDDDDDDDDDDDDDDDDDDDDDDD"}
	if validate(v) == nil {
		t.Fatal("cross-job enqueue accepted")
	}
}
