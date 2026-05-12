<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Reading;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReadingRequest;

class UserReadingController extends Controller
{
    protected $error;
    // GET /api/v1/users/{user}/readings
    public function index(User $user, Request $request)
    {
        $auth = $request->user();
        $role = strtolower((string) $auth->role);

        // If role is "user", only allow viewing own record
        if ($role === 'user' && $auth->id !== $user->id) {

            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $perPage = (int) $request->query('per_page', 25);

        $readings = $user->readings()
            ->orderByDesc('date')
            ->paginate($perPage);

        return response()->json([
            'user' => $user,
            'readings' => $readings,
        ]);
    }

    // POST /api/v1/users/{user}/readings
    public function store(StoreReadingRequest $request)
    {
        $auth = $request->user();
        $role = strtolower((string) $auth->role);
        // dd($role);
        // Users cannot create readings
        if ($role === 'users') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $data = $request->validated();
        // dd($data);

        // Ensure atomic update of reading + user fields
        $result = DB::transaction(function () use ( $data,$auth) {
            $user = User::findOrFail($data['customer_id']);
            // dd($data['customer_id']);

            // $date = $data['date'];
            $date = Carbon::parse($data['date']);
            $newValue = (int) $data['value'];

            // $previous = $user->current_reading ?? $user->previous_reading ?? null;
            # Previous reading should be last recorded reading
            $previous = $user->current_reading ?? $user->previous_reading ?? null;

            // If previous read`ing is not set yet, usage becomes 0 (first reading)
            $usage = ($previous === null) ? 0 : max(0, $newValue - (int)$previous);

            // Prevent duplicates per user per date (if you used unique(user_id, date))
            // If duplicate exists, return a nice error:
            // $exists = Reading::where('user_id', $user->id)->where('date', $date)->exists();
            $exists = Reading::where('user_id', $user->id)
                                    ->whereYear('date', $date->year)
                                    ->whereMonth('date', $date->month)
                                    ->exists();
            if ($exists) {
                $this->error = 'A reading for this month already exists.';

                return false;

                // abort(422, 'A reading already exists for this user and date.');
            }
            // Create reading
            $reading = $user->readings()->create([
                'user_id' => $user->id,
                'date' => $date,
                'value' => $newValue,
                'usage' => $newValue,
                'encoder_user_id' => $auth->id,
                'status' => $data['status'] ?? 'unpaid',
            ]);
            // Compute amount to add to balance (customize rates below)
            $charge = $this->computeCharge($user->account_type ?? 'residential', $usage);

            // Update user “current state”
            $user->previous_reading = $previous;
            $user->current_reading = $newValue;
            $user->last_usage = $usage;
            $user->billing_date = $date;

            // Optional: move status out of for_reading to ok automatically
            if ($user->status === 'for_reading') {
                $user->status = 'ok';
            }

            // Add charge to balance (or overwrite, depends on your business rule)
            $user->balance = ((float) $user->balance) + $charge;

            $user->save();

            return [
                'reading' => $reading,
                'user' => $user->fresh(),
                'charge' => $charge,
            ];
        });

        if(!$result){
            return response()->json([
                    'message' => $this->error,
                    'code' => 'DUPLICATE_MONTH',
                ], 422);
        }

        return response()->json([
            'message' => 'Reading saved successfully.',
            'charge_added' => $result['charge'],
            'reading' => $result['reading'],
            'user' => $result['user'],
        ], 201);
    }

    /**
     * Simple rate calculator (edit this to match Bacong rates)
     * Returns charge amount (float).
     */
    private function computeCharge(string $accountType, int $usage): float
    {
        // Example dummy rates:
        // residential: 25 base + 10 per unit
        // commercial:  50 base + 15 per unit
        // industrial:  100 base + 20 per unit
        // special_use: 30 base + 12 per unit

        $accountType = strtolower($accountType);

        return match ($accountType) {
            'commercial' => 50 + ($usage * 15),
            'industrial' => 100 + ($usage * 20),
            'special_use' => 30 + ($usage * 12),
            default => 25 + ($usage * 10), // residential
        };
    }
}
