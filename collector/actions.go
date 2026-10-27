package main

import (
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"errors"
	"strconv"
	"strings"
	"time"
)

type JobAction struct {
	Kind     string
	Observed float64
}

func actionFingerprint(uuid, connection, dispatch string) string {
	framed := strconv.Itoa(len(uuid)) + ":" + uuid + strconv.Itoa(len(connection)) + ":" + connection + strconv.Itoa(len(dispatch)) + ":" + dispatch
	sum := sha256.Sum256([]byte(framed))
	return hex.EncodeToString(sum[:])
}
func jobAction(tx *sql.Tx, v Event, cache map[string]*JobAction) (*JobAction, error) {
	dispatch, _ := v.Job["dispatch_id"].(string)
	if dispatch == "" {
		return nil, nil
	}
	hash := actionFingerprint(v.Job["external_id"].(string), v.Job["connection"].(string), dispatch)
	if action, found := cache[hash]; found {
		return action, nil
	}
	// InnoDB current locking read serializes even an absent marker with maintenance
	// inserts. Lock order is action identity before any job/attempt mutation.
	query := "SELECT kind,observed_at,expires_at FROM lens_job_actions WHERE identity_hash=?"
	if mysqlMode {
		query += " FOR UPDATE"
	}
	var kind string
	var observed, expires float64
	err := tx.QueryRow(query, hash).Scan(&kind, &observed, &expires)
	if err == sql.ErrNoRows {
		cache[hash] = nil
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if kind != "cleared" && kind != "forgotten" {
		return nil, errors.New("invalid maintenance action")
	}
	if expires <= float64(time.Now().UnixMilli())/1000 {
		cache[hash] = nil
		return nil, nil
	}
	action := &JobAction{kind, observed}
	cache[hash] = action
	return action, nil
}

// Expiry removes the forgotten-history retry claim in the same transaction as
// its marker. Otherwise collector pruning can strand claims before PHP pruning.
func pruneJobActions(tx *sql.Tx, now float64) error {
	query := "SELECT identity_hash,kind,job_id FROM lens_job_actions WHERE expires_at<=? ORDER BY identity_hash LIMIT 500"
	if mysqlMode {
		query += " FOR UPDATE"
	}
	rows, err := tx.Query(query, now)
	if err != nil {
		return err
	}
	var hashes, claims []interface{}
	for rows.Next() {
		var hash, kind string
		var job sql.NullString
		if err = rows.Scan(&hash, &kind, &job); err != nil {
			rows.Close()
			return err
		}
		hashes = append(hashes, hash)
		if kind == "forgotten" && job.Valid {
			claims = append(claims, "retry:"+job.String)
		}
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return err
	}
	marks := func(n int) string { return strings.TrimRight(strings.Repeat("?,", n), ",") }
	if len(claims) > 0 {
		if _, err = tx.Exec("DELETE FROM lens_meta WHERE `key` IN ("+marks(len(claims))+")", claims...); err != nil {
			return err
		}
	}
	if len(hashes) > 0 {
		args := append([]interface{}{now}, hashes...)
		_, err = tx.Exec("DELETE FROM lens_job_actions WHERE expires_at<=? AND identity_hash IN ("+marks(len(hashes))+")", args...)
	}
	return err
}

func pruneMaintenance(db *storage, now float64) error {
	tx, err := db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if err = pruneJobActions(tx, now); err != nil {
		return err
	}
	return tx.Commit()
}
