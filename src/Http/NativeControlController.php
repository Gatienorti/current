<?php

namespace Current\Http;

use Current\ExecutionBackend;
use Current\Native\Supervisor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Throwable;

final class NativeControlController
{
    public function __invoke(Request $request, Supervisor $supervisor)
    {
        abort_unless(ExecutionBackend::native(), 404);
        abort_unless(config('current.native.controls') === true && Gate::has('operateCurrent') && Gate::allows('operateCurrent'), 403);
        $input = $request->validate([
            'owner' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/D'],
            'action' => ['required', Rule::in(['pause', 'resume', 'restart', 'stop'])],
            'pool' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_-]{0,63}$/D'],
        ]);
        abort_if($input['action'] === 'stop' ? ! empty($input['pool']) : empty($input['pool']), 422);
        $health = $supervisor->snapshot();
        abort_unless($health['available'] && ! in_array($health['state'], ['stale', 'stopping', 'stopped', 'failed'], true)
            && hash_equals($health['owner'], $input['owner']), 409, 'Supervisor changed or is unavailable. Refresh before controlling it.');
        abort_if($input['action'] !== 'stop' && ! in_array($input['pool'], array_merge(array_column($health['pools'], 'name'), array_filter(array_column($health['pools'], 'group'))), true), 422);
        try {
            $accepted = $supervisor->control($input['owner'], $input['action'], $input['pool'] ?? null);
        } catch (Throwable) {
            return redirect()->route('current.dashboard')->with('current_control_notice', 'Control outcome unconfirmed. Refresh supervisor state before taking another action.');
        }
        abort_unless($accepted, 409, 'Control rejected. Refresh supervisor state.');

        return redirect()->route('current.dashboard')->with('current_control_notice', 'Control accepted. Worker transitions may take time to complete.');
    }
}
