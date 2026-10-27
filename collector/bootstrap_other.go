//go:build !linux

package main

import (
	"context"
	"errors"
)

func bootstrapCapability() int { return 0 }
func loadLaravelSettings(context.Context, []string) (Settings, error) {
	return Settings{}, errors.New("Laravel bootstrap requires Linux")
}
