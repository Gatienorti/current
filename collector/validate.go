package main

import (
	"errors"
	"math"
	"regexp"
	"time"
)

var identifier = regexp.MustCompile(`^[A-Z0-9]{26}$`)

func validate(v Event) error {
	invalid := errors.New("invalid event")
	if (v.Version != 1 && v.Version != 2) || !identifier.MatchString(v.ID) || v.Observed <= 0 || math.IsNaN(v.Observed) || v.Observed > float64(time.Now().Unix()+300) {
		return invalid
	}
	for t, row := range map[string]map[string]interface{}{"lens_jobs": v.Job, "lens_attempts": v.Attempt, "lens_steps": v.Step} {
		if len(row) == 0 {
			continue
		}
		id, ok := row["id"].(string)
		if !ok || !identifier.MatchString(id) {
			return invalid
		}
		for k, value := range row {
			if !columns[t][k] {
				return invalid
			}
			switch x := value.(type) {
			case nil, string:
			case float64:
				if math.IsNaN(x) || math.IsInf(x, 0) {
					return invalid
				}
			default:
				return invalid
			}
		}
	}
	for k, value := range v.Enqueue {
		if !columns["lens_jobs"][k] {
			return invalid
		}
		switch value.(type) {
		case nil, string, float64:
		default:
			return invalid
		}
	}
	if postgresMode {
		for _, row := range []map[string]interface{}{v.Job, v.Enqueue} {
			if value := row["silenced"]; value != nil && value != float64(0) && value != float64(1) {
				return invalid
			}
		}
	}
	for _, k := range []string{"id", "external_id", "connection", "queue", "job_class", "status", "coverage"} {
		if _, ok := v.Job[k].(string); !ok {
			return invalid
		}
	}
	for _, k := range []string{"first_observed_at", "observed_at", "expires_at"} {
		if n, ok := v.Job[k].(float64); !ok || n <= 0 {
			return invalid
		}
	}
	if v.Job["expires_at"].(float64) > v.Observed+366*86400 {
		return invalid
	}
	if v.MonitoredExpires != 0 && (v.Version != 2 || math.IsNaN(v.MonitoredExpires) || math.IsInf(v.MonitoredExpires, 0) || v.MonitoredExpires <= v.Observed || v.MonitoredExpires > v.Observed+30*86400) {
		return invalid
	}
	if len(v.Enqueue) > 0 && v.Enqueue["id"] != v.Job["id"] {
		return invalid
	}
	if v.Version == 2 && len(v.Enqueue) > 0 {
		for _, key := range []string{"external_id", "connection", "dispatch_id"} {
			if value, present := v.Enqueue[key]; present && value != v.Job[key] {
				return invalid
			}
		}
	}
	if len(v.Attempt) > 0 {
		if v.Attempt["job_id"] != v.Job["id"] || v.Revision < 1 || v.Revision > 1000000 {
			return invalid
		}
		for _, k := range []string{"worker_id", "status"} {
			if _, ok := v.Attempt[k].(string); !ok {
				return invalid
			}
		}
		for _, k := range []string{"reported_attempt", "started_at"} {
			if _, ok := v.Attempt[k].(float64); !ok {
				return invalid
			}
		}
	}
	if len(v.Step) > 0 {
		if len(v.Attempt) == 0 || v.Step["attempt_id"] != v.Attempt["id"] || v.StepRevision < 1 || v.StepRevision > 2 {
			return invalid
		}
		for _, k := range []string{"label", "status"} {
			if _, ok := v.Step[k].(string); !ok {
				return invalid
			}
		}
		for _, k := range []string{"sequence", "started_at"} {
			if _, ok := v.Step[k].(float64); !ok {
				return invalid
			}
		}
	}
	return nil
}
