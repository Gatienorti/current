package main

import (
	"database/sql"
	"fmt"
	"sort"
	"strings"
)

type Event struct {
	Version      int                    `json:"version"`
	ID           string                 `json:"id"`
	Observed     float64                `json:"observed_at"`
	Job          map[string]interface{} `json:"job"`
	Enqueue      map[string]interface{} `json:"enqueue"`
	Attempt      map[string]interface{} `json:"attempt"`
	Step         map[string]interface{} `json:"step"`
	Tags         []string               `json:"tags"`
	Revision     int                    `json:"revision"`
	StepRevision int                    `json:"step_revision"`
}

var columns = map[string]map[string]bool{}

type queryer interface {
	Query(string, ...interface{}) (*sql.Rows, error)
}

func loadColumns(db queryer) error {
	for _, t := range []string{"lens_jobs", "lens_attempts", "lens_steps"} {
		rows, e := db.Query("SELECT * FROM " + t + " WHERE 1=0")
		if e != nil {
			return e
		}
		names, e := rows.Columns()
		rows.Close()
		if e != nil {
			return e
		}
		columns[t] = map[string]bool{}
		for _, name := range names {
			columns[t][name] = true
		}
		if !columns[t]["id"] {
			return fmt.Errorf("schema missing %s", t)
		}
	}
	return nil
}
func write(tx *sql.Tx, t string, row map[string]interface{}, update bool) error {
	if row["id"] == nil {
		return fmt.Errorf("row without id")
	}
	var names []string
	for k := range row {
		if !columns[t][k] {
			return fmt.Errorf("unsupported column %s.%s", t, k)
		}
		names = append(names, k)
	}
	sort.Strings(names)
	var args []interface{}
	var marks, sets []string
	for _, k := range names {
		args = append(args, row[k])
		marks = append(marks, "?")
		if k != "id" {
			sets = append(sets, "`"+k+"`=excluded.`"+k+"`")
		}
	}
	suffix := " DO NOTHING"
	if update {
		suffix = " DO UPDATE SET " + strings.Join(sets, ",")
	}
	suffix = " ON CONFLICT(id)" + suffix
	if mysqlMode {
		sets = nil
		if update {
			for _, k := range names {
				if k != "id" {
					sets = append(sets, "`"+k+"`=?")
					args = append(args, row[k])
				}
			}
		}
		if len(sets) == 0 {
			sets = []string{"id=id"}
		}
		suffix = " ON DUPLICATE KEY UPDATE " + strings.Join(sets, ",")
	}
	_, e := tx.Exec("INSERT INTO "+t+" (`"+strings.Join(names, "`,`")+"`) VALUES ("+strings.Join(marks, ",")+")"+suffix, args...)
	return e
}
func versioned(tx *sql.Tx, t string, row map[string]interface{}, revision int, expires float64) error {
	if len(row) == 0 {
		return nil
	}
	if revision < 1 {
		return fmt.Errorf("invalid revision")
	}
	id, ok := row["id"].(string)
	if !ok {
		return fmt.Errorf("invalid id")
	}
	var previous int
	e := tx.QueryRow("SELECT revision FROM lens_async_versions WHERE entity=?", t+":"+id).Scan(&previous)
	if e != nil && e != sql.ErrNoRows {
		return e
	}
	if previous >= revision {
		return nil
	}
	if e = write(tx, t, row, true); e != nil {
		return e
	}
	_, e = tx.Exec(sqlFor("INSERT INTO lens_async_versions VALUES (?,?,?) ON CONFLICT(entity) DO UPDATE SET revision=?, expires_at=?", "INSERT INTO lens_async_versions VALUES (?,?,?) ON DUPLICATE KEY UPDATE revision=?, expires_at=?"), t+":"+id, revision, expires, revision, expires)
	return e
}
func project(tx *sql.Tx, v Event) error {
	if v.ID == "" || len(v.Job) == 0 {
		return fmt.Errorf("invalid event")
	}
	result, e := tx.Exec(sqlFor("INSERT INTO lens_async_receipts VALUES (?,?) ON CONFLICT(id) DO NOTHING", "INSERT INTO lens_async_receipts VALUES (?,?) ON DUPLICATE KEY UPDATE id=id"), v.ID, v.Observed+86400)
	if e != nil {
		return e
	}
	n, _ := result.RowsAffected()
	if n == 0 {
		return nil
	}
	if e = write(tx, "lens_jobs", v.Job, false); e != nil {
		return e
	}
	if len(v.Enqueue) > 0 {
		// Enqueue enriches a start-only row but cannot regress an already completed job.
		var sets []string
		var args []interface{}
		var keys []string
		for k := range v.Enqueue {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		for _, k := range keys {
			if !columns["lens_jobs"][k] {
				return fmt.Errorf("invalid enqueue column")
			}
			switch k {
			case "id", "status", "observed_at":
				continue
			case "first_observed_at":
				sets = append(sets, sqlFor("first_observed_at=MIN(first_observed_at,?)", "first_observed_at=LEAST(first_observed_at,?)"))
			default:
				sets = append(sets, "`"+k+"`=?")
			}
			args = append(args, v.Enqueue[k])
		}
		args = append(args, v.Job["id"])
		if _, e = tx.Exec("UPDATE lens_jobs SET "+strings.Join(sets, ",")+" WHERE id=?", args...); e != nil {
			return e
		}
		for _, tag := range v.Tags {
			if _, e = tx.Exec(sqlFor("INSERT INTO lens_tags VALUES (?,?) ON CONFLICT(job_id,tag) DO NOTHING", "INSERT INTO lens_tags VALUES (?,?) ON DUPLICATE KEY UPDATE job_id=job_id"), v.Job["id"], tag); e != nil {
				return e
			}
		}
	}
	if v.Job["silenced"] != nil {
		if _, e = tx.Exec(sqlFor("INSERT INTO lens_meta VALUES ('silenced_capture_started_at',?) ON CONFLICT(key) DO NOTHING", "INSERT INTO lens_meta VALUES ('silenced_capture_started_at',?) ON DUPLICATE KEY UPDATE `key`=`key`"), v.Job["first_observed_at"]); e != nil {
			return e
		}
	}
	if e = versioned(tx, "lens_attempts", v.Attempt, v.Revision, v.Job["expires_at"].(float64)+86400); e != nil {
		return e
	}
	if e = versioned(tx, "lens_steps", v.Step, v.StepRevision, v.Job["expires_at"].(float64)+86400); e != nil {
		return e
	}
	_, e = tx.Exec(`UPDATE lens_jobs SET attempt_count=(SELECT COUNT(*) FROM lens_attempts WHERE job_id=lens_jobs.id), status=COALESCE((SELECT CASE status WHEN 'released' THEN 'pending' WHEN 'exception' THEN 'unknown' WHEN 'timed_out' THEN 'unknown' ELSE status END FROM lens_attempts WHERE job_id=lens_jobs.id ORDER BY started_at DESC,id DESC LIMIT 1),'pending'), exception_class=(SELECT exception_class FROM lens_attempts WHERE job_id=lens_jobs.id AND exception_class IS NOT NULL ORDER BY started_at DESC,id DESC LIMIT 1), observed_at=`+sqlFor("MAX(observed_at,?)", "GREATEST(observed_at,?)")+` WHERE id=?`, v.Observed, v.Job["id"])
	if e != nil {
		return e
	}
	_, e = tx.Exec(sqlFor("INSERT INTO lens_meta VALUES ('collection_started_at',?) ON CONFLICT(key) DO UPDATE SET value=CAST(MIN(CAST(value AS REAL),?) AS TEXT)", "INSERT INTO lens_meta VALUES ('collection_started_at',?) ON DUPLICATE KEY UPDATE value=LEAST(value+0,?)"), v.Job["first_observed_at"], v.Job["first_observed_at"])
	if e != nil {
		return e
	}
	_, e = tx.Exec(sqlFor("INSERT INTO lens_meta VALUES ('last_observed_at',?) ON CONFLICT(key) DO UPDATE SET value=CAST(MAX(CAST(value AS REAL),?) AS TEXT)", "INSERT INTO lens_meta VALUES ('last_observed_at',?) ON DUPLICATE KEY UPDATE value=GREATEST(value+0,?)"), v.Observed, v.Observed)
	return e
}
