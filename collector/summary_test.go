package main

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"math/rand"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
	"time"

	"github.com/go-sql-driver/mysql"
)

func summaryEvents(seed int64) []Event {
	r := rand.New(rand.NewSource(seed))
	now := float64(time.Now().Unix())
	events := []Event{}
	for job := 0; job < 6; job++ {
		base := event(1000 + job)
		base.Version = 2
		base.Observed = now
		base.Job["id"] = fmt.Sprintf("%026d", 1000+job)
		base.Job["external_id"] = fmt.Sprintf("uuid-%d", job)
		base.Job["dispatch_id"] = fmt.Sprintf("%026d", 2000+job)
		base.Job["first_observed_at"] = now
		base.Job["observed_at"] = now
		base.Job["expires_at"] = now + 3600
		base.Job["silenced"] = float64(job % 2)
		base.Enqueue = copyRow(base.Job)
		base.Enqueue["coverage"] = "observed_enqueue"
		base.Tags = []string{"approved", fmt.Sprintf("job:%d", job)}
		events = append(events, base)
		for attempt := 1; attempt <= 3; attempt++ {
			for rev := 1; rev <= 3; rev++ {
				v := base
				v.Job = copyRow(base.Job)
				v.Enqueue = nil
				v.Tags = nil
				v.ID = fmt.Sprintf("%026d", 10000+job*100+attempt*10+rev)
				// A second producer uses a different candidate for the same dispatch.
				if rev%2 == 0 {
					v.Job["id"] = fmt.Sprintf("%026d", 3000+job)
				}
				v.Observed = now + float64(attempt*10+rev)
				v.Revision = rev
				state := "processing"
				if rev == 3 {
					state = []string{"released", "exception", "completed", "failed", "timed_out", "completed"}[(job+attempt)%6]
				}
				v.Attempt = map[string]interface{}{"id": fmt.Sprintf("%026d", 4000+job*10+attempt), "job_id": v.Job["id"], "worker_id": "worker", "reported_attempt": float64(attempt), "status": state, "started_at": now + float64(attempt*10)}
				if rev == 3 {
					v.Attempt["finished_at"] = v.Observed
					if state != "completed" {
						v.Attempt["exception_class"] = "FixtureException"
					}
				}
				v.StepRevision = 1
				if rev == 3 {
					v.StepRevision = 2
				}
				v.Step = map[string]interface{}{"id": fmt.Sprintf("%026d", 5000+job*10+attempt), "attempt_id": v.Attempt["id"], "parent_id": nil, "sequence": float64(1), "label": "Work", "status": "running", "started_at": now + float64(attempt*10)}
				if rev == 3 {
					v.Step["status"] = "completed"
					v.Step["finished_at"] = v.Observed
				}
				if attempt == 3 && rev == 3 {
					v.MonitoredExpires = now + 7*86400
				}
				events = append(events, v)
			}
		}
	}
	r.Shuffle(len(events), func(i, j int) { events[i], events[j] = events[j], events[i] })
	// Replay complete envelopes, including older revisions and enqueue arrivals.
	for i := 0; i < 20; i++ {
		events = append(events, events[r.Intn(len(events))])
	}
	return events
}
func copyRow(row map[string]interface{}) map[string]interface{} {
	out := map[string]interface{}{}
	for k, v := range row {
		out[k] = v
	}
	return out
}
func snapshotSummary(t *testing.T, db *sql.DB) string {
	t.Helper()
	all := map[string][]string{}
	for _, table := range []string{"lens_jobs", "lens_attempts", "lens_steps", "lens_tags", "lens_meta", "lens_job_actions", "lens_async_aliases", "lens_async_receipts", "lens_async_versions"} {
		rows, err := db.Query("SELECT * FROM " + table)
		if err != nil {
			t.Fatal(err)
		}
		cols, err := rows.Columns()
		if err != nil {
			t.Fatal(err)
		}
		values := make([]interface{}, len(cols))
		ptrs := make([]interface{}, len(cols))
		for i := range values {
			ptrs[i] = &values[i]
		}
		for rows.Next() {
			if err = rows.Scan(ptrs...); err != nil {
				t.Fatal(err)
			}
			for i, v := range values {
				if b, ok := v.([]byte); ok {
					values[i] = string(b)
				}
			}
			b, err := json.Marshal(values)
			if err != nil {
				t.Fatal(err)
			}
			all[table] = append(all[table], string(b))
		}
		if err = rows.Err(); err != nil {
			t.Fatal(err)
		}
		rows.Close()
		sort.Strings(all[table])
	}
	b, err := json.Marshal(all)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}
func applySummaryBatch(t *testing.T, db *sql.DB, events []Event, deferSummary bool) {
	t.Helper()
	tx, err := db.Begin()
	if err != nil {
		t.Fatal(err)
	}
	defer tx.Rollback()
	var summaries map[string]float64
	if deferSummary {
		summaries = map[string]float64{}
	}
	actions := map[string]*JobAction{}
	for _, v := range events {
		if err = validate(v); err != nil {
			t.Fatal(err)
		}
		err = projectWithSummaries(tx, v, summaries, actions)
		if err != nil && !errors.Is(err, expiredIdentity) && !errors.Is(err, conflictingIdentity) {
			t.Fatal(err)
		}
	}
	if deferSummary {
		if err = flushSummaries(tx, summaries); err != nil {
			t.Fatal(err)
		}
	}
	if err = tx.Commit(); err != nil {
		t.Fatal(err)
	}
}
func TestDeferredSummariesMatchEveryCommittedSnapshot(t *testing.T) {
	for seed := int64(0); seed < 40; seed++ {
		for _, batch := range []int{1, 7, 100} {
			t.Run(fmt.Sprintf("seed%d-batch%d", seed, batch), func(t *testing.T) {
				before, after := fixture(t), fixture(t)
				events := summaryEvents(seed)
				// Both forgotten and cleared observations must preserve maintenance semantics.
				for _, db := range []*sql.DB{before, after} {
					for job, kind := range []string{"forgotten", "cleared"} {
						_, err := db.Exec("INSERT INTO lens_job_actions VALUES(?,?,?,?,?)", actionFingerprint(fmt.Sprintf("uuid-%d", job), "redis", fmt.Sprintf("%026d", 2000+job)), kind, fmt.Sprintf("%026d", 1000+job), events[0].Job["first_observed_at"], events[0].Job["expires_at"])
						if err != nil {
							t.Fatal(err)
						}
					}
				}
				for start := 0; start < len(events); start += batch {
					end := min(start+batch, len(events))
					applySummaryBatch(t, before, events[start:end], false)
					applySummaryBatch(t, after, events[start:end], true)
					a, b := snapshotSummary(t, before), snapshotSummary(t, after)
					if a != b {
						t.Fatalf("snapshot differs after batch %d\nBASE %s\nNEW %s", start, a, b)
					}
				}
			})
		}
	}
}
func TestDeferredSummaryFailureRollsBackObservationsAndReceipts(t *testing.T) {
	db := fixture(t)
	_, err := db.Exec("CREATE TRIGGER reject_summary BEFORE UPDATE OF attempt_count ON lens_jobs BEGIN SELECT RAISE(ABORT,'fixture summary failure'); END")
	if err != nil {
		t.Fatal(err)
	}
	tx, err := db.Begin()
	if err != nil {
		t.Fatal(err)
	}
	summaries := map[string]float64{}
	if err = projectWithSummaries(tx, summaryEvents(1)[0], summaries); err != nil {
		t.Fatal(err)
	}
	if err = flushSummaries(tx, summaries); err == nil {
		t.Fatal("expected summary failure")
	}
	tx.Rollback()
	for _, table := range []string{"lens_jobs", "lens_attempts", "lens_steps", "lens_meta", "lens_async_aliases", "lens_async_versions", "lens_async_receipts"} {
		var n int
		if err = db.QueryRow("SELECT COUNT(*) FROM " + table).Scan(&n); err != nil || n != 0 {
			t.Fatal("partial failed transaction", table, n, err)
		}
	}
}

func summaryServerFixture(t *testing.T) *sql.DB {
	t.Helper()
	cfg := mysql.NewConfig()
	cfg.User = "root"
	cfg.Passwd = os.Getenv("CURRENT_TEST_SQL_PASSWORD")
	cfg.Net = "tcp"
	cfg.Addr = os.Getenv("CURRENT_TEST_SQL_HOST") + ":3306"
	cfg.Timeout = time.Second
	cfg.ReadTimeout = 5 * time.Second
	cfg.WriteTimeout = 5 * time.Second
	if directory := os.Getenv("CURRENT_ASYNC_TLS_DIR"); directory != "" {
		config, err := databaseTLS(SQLTLS{Enabled: true, CA: filepath.Join(directory, "ca.pem"), Cert: filepath.Join(directory, "client.pem"), Key: filepath.Join(directory, "client-key.pem")}, os.Getenv("CURRENT_TEST_SQL_HOST"))
		if err != nil {
			t.Fatal(err)
		}
		name := fmt.Sprintf("current-summary-%d", time.Now().UnixNano())
		if err := mysql.RegisterTLSConfig(name, config); err != nil {
			t.Fatal(err)
		}
		t.Cleanup(func() { mysql.DeregisterTLSConfig(name) })
		cfg.TLSConfig = name
	}
	admin, err := sql.Open("mysql", cfg.FormatDSN())
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { admin.Close() })
	name := fmt.Sprintf("current_summary_%d", time.Now().UnixNano())
	if _, err = admin.Exec("CREATE DATABASE `" + name + "`"); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		if _, err := admin.Exec("DROP DATABASE `" + name + "`"); err != nil {
			t.Error(err)
		}
	})
	cfg.DBName = name
	db, err := sql.Open("mysql", cfg.FormatDSN())
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { db.Close() })
	schema := strings.NewReplacer(" TEXT", " VARCHAR(255)", " REAL", " DOUBLE").Replace(strings.ReplaceAll(summaryFixtureSchema, "lens_meta(key ", "lens_meta(`key` "))
	for _, ddl := range strings.Split(schema, ";") {
		if strings.TrimSpace(ddl) != "" {
			if _, err = db.Exec(ddl + " ENGINE=InnoDB"); err != nil {
				t.Fatal(err)
			}
		}
	}
	if err = loadColumns(db); err != nil {
		t.Fatal(err)
	}
	return db
}
func TestDeferredSummariesOnServerSQL(t *testing.T) {
	if os.Getenv("CURRENT_TEST_SQL_HOST") == "" {
		t.Skip("requires disposable SQL server")
	}
	old := mysqlMode
	mysqlMode = true
	defer func() { mysqlMode = old }()
	for seed := int64(0); seed < 8; seed++ {
		for _, batch := range []int{1, 7, 100} {
			t.Run(fmt.Sprintf("seed%d-batch%d", seed, batch), func(t *testing.T) {
				before, after := summaryServerFixture(t), summaryServerFixture(t)
				events := summaryEvents(seed)
				for start := 0; start < len(events); start += batch {
					end := min(start+batch, len(events))
					applySummaryBatch(t, before, events[start:end], false)
					applySummaryBatch(t, after, events[start:end], true)
					if a, b := snapshotSummary(t, before), snapshotSummary(t, after); a != b {
						t.Fatalf("SQL snapshot differs after batch %d\nBASE %s\nNEW %s", start, a, b)
					}
				}
			})
		}
	}
}
