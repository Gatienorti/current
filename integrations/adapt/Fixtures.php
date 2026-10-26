<?php

namespace Current\AdaptLab;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class Fixtures
{
    public static function seed(): array
    {
        $users = [];
        foreach (['operator' => 'super_admin', 'member' => 'user'] as $name => $role) {
            $users[$name] = User::withoutEvents(fn () => User::firstOrCreate(['email' => $name.'@lens.invalid'], [
                'name' => 'Synthetic '.$name, 'password' => Hash::make('lens-local-only'),
                'role' => $role, 'email_verified_at' => now(),
            ]));
        }
        $user = $users['operator'];
        $plan = DB::table('training_plans')->insertGetId([
            'user_id' => $user->id, 'title' => 'Synthetic Lens plan', 'training_days' => '[1,3,6]',
            'long_run_day' => 6, 'race_distance' => '10k', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('garmin_connections')->updateOrInsert(['user_id' => $user->id], [
            'user_id' => $user->id, 'garmin_user_id' => 'synthetic-lens', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $connection = DB::table('garmin_connections')->where('user_id', $user->id)->value('id');
        $notifications = [];
        foreach (['success', 'retry', 'failure'] as $scenario) {
            // Fixtures bypass notification observers so only explicitly selected jobs run.
            $notifications[$scenario] = DB::table('app_notifications')->insertGetId([
                'user_id' => $user->id, 'title' => 'Synthetic '.$scenario, 'message' => 'Local test only',
                'type' => 'info', 'read' => false, 'data' => json_encode(['lens_scenario' => $scenario]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return compact('users', 'plan', 'connection', 'notifications');
    }
}
