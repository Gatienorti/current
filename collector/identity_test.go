package main

import (
	"fmt"
	"testing"
)

func TestCandidateResolvesToRetainedDispatchWithoutExtendingRetention(t *testing.T) {
	db := fixture(t)
	original := identityEvent(101)
	original.Job["dispatch_id"] = "dispatch-a"
	apply(t, db, original)
	finish := identityEvent(102)
	finish.Job["id"] = "01BBBBBBBBBBBBBBBBBBBBBBBB"
	finish.Job["dispatch_id"] = "dispatch-a"
	finish.Job["expires_at"] = original.Job["expires_at"].(float64) + 100
	finish.Attempt = map[string]interface{}{"id": "01CCCCCCCCCCCCCCCCCCCCCCCC", "job_id": finish.Job["id"], "worker_id": "worker", "reported_attempt": float64(1), "status": "completed", "started_at": finish.Observed, "finished_at": finish.Observed + 1}
	finish.Revision = 1
	db.Exec("INSERT INTO lens_webhook_deliveries VALUES(?,?)", "before", finish.Job["id"])
	apply(t, db, finish)
	var count int
	var job string
	var expiry float64
	db.QueryRow("SELECT COUNT(*) FROM lens_jobs").Scan(&count)
	if count != 1 {
		t.Fatalf("duplicate histories: %d", count)
	}
	db.QueryRow("SELECT job_id FROM lens_attempts").Scan(&job)
	if job != original.Job["id"] {
		t.Fatal("attempt not canonical")
	}
	db.QueryRow("SELECT expires_at FROM lens_jobs").Scan(&expiry)
	if expiry != original.Job["expires_at"] {
		t.Fatal("retention extended")
	}
	// The producer's provisional ID remains stable across a later cache miss.
	db.Exec("INSERT INTO lens_broadcasts VALUES(?,?)", "after", finish.Job["id"])
	later := finish
	later.ID = fmt.Sprintf("%026d", 103)
	later.Revision = 2
	apply(t, db, later)
	db.QueryRow("SELECT COUNT(*) FROM lens_attempts").Scan(&count)
	if count != 1 {
		t.Fatal("attempt replay duplicated")
	}
	for _, table := range []string{"lens_webhook_deliveries", "lens_broadcasts"} {
		db.QueryRow("SELECT COALESCE(a.canonical_id,r.job_id) FROM " + table + " r LEFT JOIN lens_async_aliases a ON a.id=r.job_id").Scan(&job)
		if job != original.Job["id"] {
			t.Fatal("related record not canonical", table)
		}
	}
	retry := identityEvent(104)
	retry.Job["id"] = "01DDDDDDDDDDDDDDDDDDDDDDDD"
	retry.Job["dispatch_id"] = "dispatch-b"
	apply(t, db, retry)
	db.QueryRow("SELECT COUNT(*) FROM lens_jobs").Scan(&count)
	if count != 2 {
		t.Fatal("retry generation merged")
	}
}

func TestExpiredHistoryIsNotReused(t *testing.T) {
	db := fixture(t)
	old := identityEvent(201)
	old.Job["dispatch_id"] = "one"
	old.Job["expires_at"] = old.Observed - 1
	// Seed a retained-but-expired row directly; a live collector discards expired events.
	tx, _ := db.Begin()
	if err := write(tx, "lens_jobs", old.Job, false); err != nil {
		t.Fatal(err)
	}
	tx.Commit()
	next := identityEvent(202)
	next.Job["id"] = "01EEEEEEEEEEEEEEEEEEEEEEEE"
	next.Job["dispatch_id"] = "one"
	apply(t, db, next)
	var id string
	db.QueryRow("SELECT canonical_id FROM lens_async_aliases WHERE id=?", next.Job["id"]).Scan(&id)
	if id != next.Job["id"] {
		t.Fatal("expired cycle reused")
	}
}

func TestLateRelatedRowResolvesWithoutAnotherTelemetryEvent(t *testing.T) {
	db := fixture(t)
	original := identityEvent(301)
	apply(t, db, original)
	next := identityEvent(302)
	next.Job["id"] = "01FFFFFFFFFFFFFFFFFFFFFFFF"
	apply(t, db, next)
	// No completion or step event follows this insert (e.g. worker killed).
	if _, err := db.Exec("INSERT INTO lens_broadcasts VALUES(?,?)", "late", next.Job["id"]); err != nil {
		t.Fatal(err)
	}
	var id string
	err := db.QueryRow("SELECT j.id FROM lens_broadcasts b LEFT JOIN lens_async_aliases a ON a.id=b.job_id JOIN lens_jobs j ON j.id=COALESCE(a.canonical_id,b.job_id) WHERE j.expires_at>?", next.Observed).Scan(&id)
	if err != nil || id != original.Job["id"] {
		t.Fatal("late relation lost", id, err)
	}
}

func TestAliasCannotBeReboundToAnotherDispatch(t *testing.T) {
	db := fixture(t)
	v := identityEvent(401)
	v.Job["dispatch_id"] = "first"
	apply(t, db, v)
	v.ID = fmt.Sprintf("%026d", 402)
	v.Job["dispatch_id"] = "second"
	tx, _ := db.Begin()
	defer tx.Rollback()
	if _, err := canonicalIdentity(tx, v); err != conflictingIdentity {
		t.Fatal("identity collision accepted", err)
	}
}

func TestExpiredAliasDoesNotResurrectOrJoinNewCycle(t *testing.T) {
	db := fixture(t)
	old := identityEvent(501)
	apply(t, db, old)
	if _, err := db.Exec("UPDATE lens_async_aliases SET job_expires_at=?", old.Observed-1); err != nil {
		t.Fatal(err)
	}
	db.Exec("DELETE FROM lens_jobs")
	fresh := identityEvent(502)
	fresh.Job["id"] = "01GGGGGGGGGGGGGGGGGGGGGGGG"
	apply(t, db, fresh)
	old.ID = fmt.Sprintf("%026d", 503)
	tx, _ := db.Begin()
	defer tx.Rollback()
	if _, err := canonicalIdentity(tx, old); err != expiredIdentity {
		t.Fatal("expired alias accepted", err)
	}
}

func identityEvent(n int) Event { v := event(n); v.Version = 2; return v }

func TestCandidateCannotOverwriteAnotherRetainedJob(t *testing.T) {
	db := fixture(t)
	v := event(601)
	apply(t, db, v)
	incoming := identityEvent(602)
	incoming.Job["external_id"] = "different"
	tx, _ := db.Begin()
	defer tx.Rollback()
	if _, err := canonicalIdentity(tx, incoming); err != conflictingIdentity {
		t.Fatal("candidate collision accepted", err)
	}
}

func TestLegacyEventsKeepTheirExistingProjectionSemantics(t *testing.T) {
	db := fixture(t)
	apply(t, db, event(701))
	var count int
	if err := db.QueryRow("SELECT COUNT(*) FROM lens_async_aliases").Scan(&count); err != nil || count != 0 {
		t.Fatal("legacy event created alias", count, err)
	}
}

func TestVersionTwoEnqueueCannotChangeDispatchIdentity(t *testing.T) {
	fixture(t)
	v := identityEvent(801)
	v.Enqueue = map[string]interface{}{"id": v.Job["id"], "external_id": "other"}
	if validate(v) == nil {
		t.Fatal("mismatched enqueue accepted")
	}
}
