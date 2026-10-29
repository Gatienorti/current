package main

import (
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"time"
)

var expiredIdentity = errors.New("expired identity")
var conflictingIdentity = errors.New("conflicting identity")

// Version 2 producer IDs are provisional. A durable alias is bound to the
// dispatch identity, and survives the job's retention by the maximum event age.
// Readers must resolve aliases too: a related row may arrive after the final event.
func canonicalIdentity(tx *sql.Tx, v Event) (Event, error) {
	candidate := v.Job["id"].(string)
	dispatch, _ := v.Job["dispatch_id"].(string)
	encoded, _ := json.Marshal([]interface{}{v.Job["external_id"], v.Job["connection"], dispatch})
	digest := sha256.Sum256(encoded)
	identity := hex.EncodeToString(digest[:])
	var canonical, storedIdentity string
	var first, expiry float64
	err := queryRowSQL(tx, "SELECT canonical_id, identity_hash, first_observed_at, job_expires_at FROM lens_async_aliases WHERE id=?", candidate).Scan(&canonical, &storedIdentity, &first, &expiry)
	if err == sql.ErrNoRows {
		var external, connection, existingDispatch string
		collision := queryRowSQL(tx, "SELECT external_id, connection, COALESCE(dispatch_id,'') FROM lens_jobs WHERE id=?", candidate).Scan(&external, &connection, &existingDispatch)
		if collision != nil && collision != sql.ErrNoRows {
			return v, collision
		}
		if collision == nil && (external != v.Job["external_id"] || connection != v.Job["connection"] || existingDispatch != dispatch) {
			return v, conflictingIdentity
		}
		query := "SELECT id,first_observed_at,expires_at FROM lens_jobs WHERE external_id=? AND connection=? AND expires_at>?"
		args := []interface{}{v.Job["external_id"], v.Job["connection"], v.Observed}
		if dispatch != "" {
			query += " AND dispatch_id=?"
			args = append(args, dispatch)
		}
		query += " ORDER BY first_observed_at DESC,id DESC LIMIT 1"
		err = queryRowSQL(tx, query, args...).Scan(&canonical, &first, &expiry)
		if err == sql.ErrNoRows {
			canonical = candidate
			first = v.Job["first_observed_at"].(float64)
			expiry = v.Job["expires_at"].(float64)
			err = nil
		}
		if err != nil {
			return v, err
		}
		// A candidate may stay in a producer cache longer than the canonical row.
		// Keep the tombstone through BOTH retention horizons plus max event age.
		tombstone := expiry
		if proposed := v.Job["expires_at"].(float64); proposed > tombstone {
			tombstone = proposed
		}
		_, err = execSQL(tx, "INSERT INTO lens_async_aliases(id,canonical_id,identity_hash,first_observed_at,job_expires_at,expires_at) VALUES(?,?,?,?,?,?)", candidate, canonical, identity, first, expiry, tombstone+86400)
		storedIdentity = identity
	}
	if err != nil {
		return v, err
	}
	if identity != storedIdentity {
		return v, conflictingIdentity
	}
	if expiry <= float64(time.Now().UnixMilli())/1000 || expiry <= v.Observed {
		return v, expiredIdentity
	}
	// Only the explicitly validated monitored-retention observation can extend
	// a retained canonical dispatch. Ordinary cache candidates never slide expiry.
	if v.MonitoredExpires > expiry {
		expiry = v.MonitoredExpires
		if _, err = execSQL(tx, "UPDATE lens_jobs SET expires_at="+sqlFor("MAX(expires_at,?)", "GREATEST(expires_at,?)", "GREATEST(expires_at,?)")+" WHERE id=?", expiry, canonical); err != nil {
			return v, err
		}
		if _, err = execSQL(tx, "UPDATE lens_async_aliases SET job_expires_at="+sqlFor("MAX(job_expires_at,?)", "GREATEST(job_expires_at,?)", "GREATEST(job_expires_at,?)")+",expires_at="+sqlFor("MAX(expires_at,?)", "GREATEST(expires_at,?)", "GREATEST(expires_at,?)")+" WHERE canonical_id=? AND identity_hash=?", expiry, expiry+86400, canonical, identity); err != nil {
			return v, err
		}
		// Earlier attempts and steps must keep their revision fences throughout
		// the extended history window, even if this event has no child snapshot.
		if _, err = execSQL(tx, "UPDATE lens_async_versions SET expires_at="+sqlFor("MAX(expires_at,?)", "GREATEST(expires_at,?)", "GREATEST(expires_at,?)")+" WHERE entity IN (SELECT "+sqlFor("'lens_attempts:' || id", "CONCAT('lens_attempts:',id)")+" FROM lens_attempts WHERE job_id=? UNION ALL SELECT "+sqlFor("'lens_steps:' || s.id", "CONCAT('lens_steps:',s.id)")+" FROM lens_steps s JOIN lens_attempts a ON a.id=s.attempt_id WHERE a.job_id=?)", expiry+86400, canonical, canonical); err != nil {
			return v, err
		}
	}
	clone := func(row map[string]interface{}) map[string]interface{} {
		copy := map[string]interface{}{}
		for k, value := range row {
			copy[k] = value
		}
		return copy
	}
	v.Job = clone(v.Job)
	v.Enqueue = clone(v.Enqueue)
	v.Attempt = clone(v.Attempt)
	v.Job["id"] = canonical
	v.Job["first_observed_at"] = first
	v.Job["expires_at"] = expiry
	if len(v.Enqueue) > 0 {
		v.Enqueue["id"] = canonical
		v.Enqueue["first_observed_at"] = first
		v.Enqueue["expires_at"] = expiry
	}
	if len(v.Attempt) > 0 {
		v.Attempt["job_id"] = canonical
	}
	return v, nil
}
