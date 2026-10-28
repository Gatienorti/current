//go:build linux

package main

import (
	"context"
	"time"
)

type activeSlot struct {
	cancel     context.CancelFunc
	generation int
	stopping   bool
}
type slotExit struct {
	slot int
	err  error
}

// A draining slot keeps its capacity token until managedWorker reaps its process.
// Regrowth cannot replace that slot early or exceed the instance's live-worker cap.
func runPool(ctx context.Context, p Pool, pi int, control *poolControl, h *healthState, grace time.Duration, capacity, groupCapacity chan struct{}) error {
	active := map[int]*activeSlot{}
	exits := make(chan slotExit, p.MaxWorkers)
	ticker := time.NewTicker(100 * time.Millisecond)
	defer ticker.Stop()
	var failure error
	for {
		target, paused, generation := control.desired()
		stopping := ctx.Err() != nil || failure != nil
		for slot, w := range active {
			if stopping || paused || slot >= target || w.generation != generation {
				if !w.stopping {
					w.stopping = true
					w.cancel()
				}
			}
		}
		if stopping && len(active) == 0 {
			return failure
		}
		// Keep draining slots in health even after their desired allocation shrinks.
		size := target
		for slot := range active {
			if slot >= size {
				size = slot + 1
			}
		}
		h.mu.Lock()
		for len(h.value.Pools[pi].Slots) < size {
			h.value.Pools[pi].Slots = append(h.value.Pools[pi].Slots, SlotHealth{State: "stopped"})
		}
		if len(h.value.Pools[pi].Slots) > size {
			h.value.Pools[pi].Slots = h.value.Pools[pi].Slots[:size]
		}
		if paused {
			for slot := range h.value.Pools[pi].Slots {
				if _, ok := active[slot]; !ok {
					h.value.Pools[pi].Slots[slot].State = "paused"
				}
			}
		}
		h.mu.Unlock()
		if !stopping && !paused {
			for slot := 0; slot < target; slot++ {
				if _, ok := active[slot]; ok {
					continue
				}
				select {
				case capacity <- struct{}{}:
				default:
					continue
				}
				if groupCapacity != nil {
					select {
					case groupCapacity <- struct{}{}:
					default:
						<-capacity
						continue
					}
				}
				workerCtx, cancel := context.WithCancel(ctx)
				active[slot] = &activeSlot{cancel: cancel, generation: generation}
				h.mu.Lock()
				offset := h.value.Pools[pi].Slots[slot].Starts
				h.mu.Unlock()
				go func(slot, offset int) {
					err := managedWorkerIdentity(workerCtx, p.Command, grace, slot, p.Directory, 10, func(state string, pid, starts int, identity *ProcessIdentity) {
						h.slot(pi, slot, state, pid, starts+offset, identity)
					})
					if groupCapacity != nil {
						<-groupCapacity
					}
					<-capacity
					exits <- slotExit{slot, err}
				}(slot, offset)
			}
		}
		select {
		case result := <-exits:
			active[result.slot].cancel()
			delete(active, result.slot)
			if result.err != nil && failure == nil {
				failure = result.err
			}
		case <-control.wake:
		case <-ticker.C:
		}
	}
}
