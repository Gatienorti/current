package main

import (
	"context"
	"database/sql"
	"fmt"
	"os"
	"strings"
	"testing"
	"time"
)

func postgresFixture(t *testing.T) (SQLSettings, *sql.DB) {
	t.Helper()
	host := os.Getenv("CURRENT_TEST_PG_HOST")
	if host == "" {
		t.Skip("requires disposable PostgreSQL with database creation privileges")
	}
	s := SQLSettings{Driver: "pgsql", Host: host, Port: 5432, Database: "current_async_test", Username: "postgres", Password: os.Getenv("CURRENT_TEST_PG_PASSWORD")}
	if tls := os.Getenv("CURRENT_ASYNC_TLS_DIR"); tls != "" {
		s.TLS = SQLTLS{Enabled: true, CA: tls + "/ca.pem", Cert: tls + "/client.pem", Key: tls + "/client-key.pem"}
	}
	admin, err := openPostgres(s)
	if err != nil {
		t.Fatal(err)
	}
	name := fmt.Sprintf("current_pg_%d", time.Now().UnixNano())
	if _, err = admin.Exec("CREATE DATABASE " + name); err != nil {
		admin.Close()
		t.Fatal(err)
	}
	s.Database = name
	db, err := openPostgres(s)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { db.Close(); admin.Exec("DROP DATABASE " + name + " WITH (FORCE)"); admin.Close() })
	schema := strings.NewReplacer("REAL", "DOUBLE PRECISION", "silenced INTEGER", "silenced BOOLEAN", "`", "\"").Replace(summaryFixtureSchema)
	for _, q := range strings.Split(schema, ";") {
		if strings.TrimSpace(q) != "" {
			if _, err = db.Exec(q); err != nil {
				t.Fatal(err)
			}
		}
	}
	return s, db
}

func TestPostgresProjectionOwnershipAndRecovery(t *testing.T) {
	s, db := postgresFixture(t)
	oldPG, oldMy := postgresMode, mysqlMode
	postgresMode, mysqlMode = true, false
	defer func() { postgresMode, mysqlMode = oldPG, oldMy }()
	if err := loadColumns(db); err != nil {
		t.Fatal(err)
	}
	owner, err := openStorage(Settings{SQL: s})
	if err != nil {
		t.Fatal(err)
	}
	if _, err := owner.conn.ExecContext(context.Background(), "SET default_transaction_isolation='repeatable read'"); err != nil {
		t.Fatal(err)
	}
	transaction, err := owner.Begin()
	if err != nil {
		t.Fatal(err)
	}
	var isolation string
	if err = transaction.QueryRow("SHOW transaction_isolation").Scan(&isolation); err != nil || isolation != "read committed" {
		t.Fatalf("collector isolation: %s %v", isolation, err)
	}
	transaction.Rollback()
	if second, err := openStorage(Settings{SQL: s}); err == nil {
		second.Close()
		t.Fatal("second collector acquired database")
	}
	owner.Close()
	next, err := openStorage(Settings{SQL: s})
	if err != nil {
		t.Fatal(err)
	}
	next.Close()
	for seed := int64(0); seed < 6; seed++ {
		for _, size := range []int{1, 7, 100} {
			events := summaryEvents(seed)
			reset := func() {
				for _, table := range []string{"lens_jobs", "lens_attempts", "lens_steps", "lens_tags", "lens_meta", "lens_job_actions", "lens_async_aliases", "lens_async_receipts", "lens_async_versions"} {
					if _, err := db.Exec("TRUNCATE " + table); err != nil {
						t.Fatal(err)
					}
				}
			}
			snapshots := []string{}
			reset()
			for i := 0; i < len(events); i += size {
				end := min(i+size, len(events))
				applySummaryBatch(t, db, events[i:end], false)
				snapshots = append(snapshots, snapshotSummary(t, db))
			}
			reset()
			for i := 0; i < len(events); i += size {
				end := min(i+size, len(events))
				applySummaryBatch(t, db, events[i:end], true)
				if got := snapshotSummary(t, db); got != snapshots[i/size] {
					t.Fatalf("seed %d batch %d snapshot mismatch", seed, size)
				}
			}
		}
	}
	// A rolled back receipt must not suppress the next delivery.
	v := event(999)
	tx, _ := db.Begin()
	if err := project(tx, v); err != nil {
		t.Fatal(err)
	}
	tx.Rollback()
	applySummaryBatch(t, db, []Event{v, v}, true)
	var n int
	if err := db.QueryRow("SELECT count(*) FROM lens_async_receipts WHERE id=$1", v.ID).Scan(&n); err != nil || n != 1 {
		t.Fatalf("replay %d %v", n, err)
	}
}

func TestPostgresAbsentMaintenanceLock(t *testing.T) {
	_, db := postgresFixture(t)
	old := postgresMode
	postgresMode = true
	defer func() { postgresMode = old }()
	tx, err := db.BeginTx(context.Background(), &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		t.Fatal(err)
	}
	defer tx.Rollback()
	v := Event{Job: map[string]interface{}{"external_id": "uuid", "connection": "redis", "dispatch_id": "dispatch"}}
	if action, err := jobAction(tx, v, map[string]*JobAction{}); err != nil || action != nil {
		t.Fatalf("%v %v", action, err)
	}
	done := make(chan error, 1)
	go func() {
		peer, e := db.Begin()
		if e != nil {
			done <- e
			return
		}
		defer peer.Rollback()
		hash := actionFingerprint("uuid", "redis", "dispatch")
		if _, e = execSQL(peer, "SELECT pg_advisory_xact_lock(?)", advisoryKey("current-action:"+hash)); e == nil {
			_, e = execSQL(peer, "INSERT INTO lens_job_actions VALUES(?,?,?,?,?)", hash, "forgotten", "job", 1, 2)
		}
		if e == nil {
			e = peer.Commit()
		}
		done <- e
	}()
	select {
	case e := <-done:
		t.Fatalf("missing identity lock bypassed: %v", e)
	case <-time.After(150 * time.Millisecond):
	}
	if err = tx.Commit(); err != nil {
		t.Fatal(err)
	}
	select {
	case e := <-done:
		if e != nil {
			t.Fatal(e)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("maintenance did not resume")
	}
	conn, err := db.Conn(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	if err = pruneMaintenance(&storage{conn: conn}, 3); err != nil {
		t.Fatal(err)
	}
	var n int
	db.QueryRow("SELECT count(*) FROM lens_job_actions").Scan(&n)
	if n != 0 {
		t.Fatal("prune left expired marker")
	}
}

func TestPostgresBindingPreservesLiteralData(t *testing.T) {
	old := postgresMode
	postgresMode = true
	defer func() { postgresMode = old }()
	got := bindSQL("SELECT `key`, 'isn''t ? ` quoted' FROM lens_meta WHERE value=? AND `key`=?")
	if got != `SELECT "key", 'isn''t ? `+"`"+` quoted' FROM lens_meta WHERE value=$1 AND "key"=$2` {
		t.Fatal(got)
	}
	if advisoryKey("current:example") != 1137714357111716114 {
		t.Fatal("invalid key")
	}
}
