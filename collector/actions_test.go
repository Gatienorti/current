package main

import (
	"fmt"
	"testing"
	"time"
)

func TestForgottenNativeDispatchCannotBeRecreatedByBufferedSnapshots(t *testing.T) {
	db := fixture(t)
	old := identityEvent(901)
	old.Job["dispatch_id"] = "native-generation-one"
	apply(t, db, old)
	hash := actionFingerprint("uuid", "redis", "native-generation-one")
	_, err := db.Exec("INSERT INTO lens_job_actions VALUES(?,?,?,?,?)", hash, "forgotten", old.Job["id"], old.Observed, old.Observed+86400)
	if err != nil {
		t.Fatal(err)
	}
	db.Exec("DELETE FROM lens_jobs")
	late := identityEvent(902)
	late.Job["id"] = "01HHHHHHHHHHHHHHHHHHHHHHHH"
	late.Job["dispatch_id"] = "native-generation-one"
	apply(t, db, late)
	var count int
	db.QueryRow("SELECT COUNT(*) FROM lens_jobs").Scan(&count)
	if count != 0 {
		t.Fatal("removed history recreated")
	}
	late.ID = "01JJJJJJJJJJJJJJJJJJJJJJJJ"
	late.Job["dispatch_id"] = "native-generation-two"
	apply(t, db, late)
	db.QueryRow("SELECT COUNT(*) FROM lens_jobs").Scan(&count)
	if count != 1 {
		t.Fatal("new native generation was suppressed")
	}
}

func TestClearMarkerAppliesToLateEnqueueButKeepsExecutionEvidence(t *testing.T) {
	db := fixture(t)
	v := identityEvent(911)
	v.Job["dispatch_id"] = "cleared-generation"
	_, err := db.Exec("INSERT INTO lens_job_actions VALUES(?,?,?,?,?)", actionFingerprint("uuid", "redis", "cleared-generation"), "cleared", nil, v.Observed, v.Observed+86400)
	if err != nil {
		t.Fatal(err)
	}
	apply(t, db, v)
	var cleared float64
	db.QueryRow("SELECT cleared_at FROM lens_jobs").Scan(&cleared)
	if cleared != v.Observed {
		t.Fatal("missing marker")
	}
	done := identityEvent(912)
	done.Job["dispatch_id"] = "cleared-generation"
	done.Revision = 2
	done.Attempt = map[string]interface{}{"id": "01KKKKKKKKKKKKKKKKKKKKKKKK", "job_id": done.Job["id"], "worker_id": "worker", "reported_attempt": float64(1), "status": "completed", "started_at": done.Observed, "finished_at": done.Observed + 1}
	apply(t, db, done)
	var status string
	db.QueryRow("SELECT status FROM lens_jobs").Scan(&status)
	if status != "completed" {
		t.Fatal("clear hid actual completion")
	}
	db.Exec("UPDATE lens_job_actions SET expires_at=?", float64(time.Now().Unix()-1))
	tx, _ := db.Begin()
	defer tx.Rollback()
	action, err := jobAction(tx, v, map[string]*JobAction{})
	if err != nil || action != nil {
		t.Fatal("expired action reused", err)
	}
}

func TestActionFingerprintHasPortableByteFraming(t *testing.T) {
	if actionFingerprint("u/é", "redis", "01AAAAAAAAAAAAAAAAAAAAAAAA") != "6eca7adf5be2d236ae132ba172cf6b9039f69c2fa7bd21045d45ac517dba0d6e" {
		t.Fatal("cross-language identity changed")
	}
}

func TestExpiredMaintenanceAndRetryClaimsPruneAtomicallyInBoundedBatches(t *testing.T) {
	db := fixture(t)
	for i := 0; i < 501; i++ {
		hash, id := fmt.Sprintf("%064d", i), fmt.Sprintf("%026d", i)
		if _, err := db.Exec("INSERT INTO lens_job_actions VALUES(?,?,?,?,?)", hash, "forgotten", id, 1, 2); err != nil {
			t.Fatal(err)
		}
		if _, err := db.Exec("INSERT INTO lens_meta VALUES(?,?)", "retry:"+id, `{"state":"forgotten"}`); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := db.Exec("INSERT INTO lens_job_actions VALUES(?,?,?,?,?)", "live", "forgotten", "live-job", 1, 100); err != nil {
		t.Fatal(err)
	}
	db.Exec("INSERT INTO lens_meta VALUES(?,?)", "retry:live-job", `{"state":"forgotten"}`)
	db.Exec("INSERT INTO lens_meta VALUES(?,?)", "unrelated", "keep")
	tx, _ := db.Begin()
	if err := pruneJobActions(tx, 3); err != nil {
		tx.Rollback()
		t.Fatal(err)
	}
	if err := tx.Commit(); err != nil {
		t.Fatal(err)
	}
	var actions, meta int
	db.QueryRow("SELECT COUNT(*) FROM lens_job_actions").Scan(&actions)
	db.QueryRow("SELECT COUNT(*) FROM lens_meta").Scan(&meta)
	if actions != 2 || meta != 3 {
		t.Fatalf("bounded prune removed wrong rows: actions=%d meta=%d", actions, meta)
	}
	// If the second delete fails, the first delete must roll back too.
	db.Exec("CREATE TRIGGER block_action_delete BEFORE DELETE ON lens_job_actions BEGIN SELECT RAISE(ABORT,'fixture'); END")
	tx, _ = db.Begin()
	if err := pruneJobActions(tx, 3); err == nil {
		tx.Rollback()
		t.Fatal("expected action deletion failure")
	}
	tx.Rollback()
	db.QueryRow("SELECT COUNT(*) FROM lens_meta").Scan(&meta)
	if meta != 3 {
		t.Fatal("retry claim escaped rolled-back marker pruning")
	}
	db.Exec("DROP TRIGGER block_action_delete")
	tx, _ = db.Begin()
	if err := pruneJobActions(tx, 3); err != nil {
		tx.Rollback()
		t.Fatal(err)
	}
	if err := tx.Commit(); err != nil {
		t.Fatal(err)
	}
	db.QueryRow("SELECT COUNT(*) FROM lens_job_actions").Scan(&actions)
	db.QueryRow("SELECT COUNT(*) FROM lens_meta").Scan(&meta)
	if actions != 1 || meta != 2 {
		t.Fatalf("live/unrelated data changed: actions=%d meta=%d", actions, meta)
	}
}

func TestForgottenLegacyDispatchCannotBeRecreatedButNewNativeDispatchRemainsVisible(t *testing.T) {
	db := fixture(t)
	v := identityEvent(980)
	delete(v.Job, "dispatch_id")
	apply(t, db, v)
	_, err := db.Exec("INSERT INTO lens_job_actions VALUES(?,?,?,?,?)", actionFingerprint("uuid", "redis", ""), "forgotten", v.Job["id"], v.Observed, v.Observed+86400)
	if err != nil {
		t.Fatal(err)
	}
	db.Exec("DELETE FROM lens_jobs")
	late := identityEvent(981)
	delete(late.Job, "dispatch_id")
	apply(t, db, late)
	var count int
	db.QueryRow("SELECT COUNT(*) FROM lens_jobs").Scan(&count)
	if count != 0 {
		t.Fatal("forgotten legacy history recreated")
	}
	next := identityEvent(982)
	next.Job["id"] = "01MMMMMMMMMMMMMMMMMMMMMMMM"
	next.Job["dispatch_id"] = "new-native-dispatch"
	apply(t, db, next)
	db.QueryRow("SELECT COUNT(*) FROM lens_jobs").Scan(&count)
	if count != 1 {
		t.Fatal("fresh native dispatch was suppressed by a legacy marker")
	}
}
