<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class NewApplicationController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'account_name' => ['required', 'string', 'max:255'],

            'account_number' => [
                'required',
                'string',
                'min:5',
                'max:255',
                'regex:/^\S+$/',
                'unique:users,account_number',
            ],

            // New optional meter number
            'meter_no' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^\S+$/',
                'unique:users,meter_no',
            ],

            'mobile' => [
                'required',
                'digits:11',
                'regex:/^09\d{9}$/',
                'unique:users,mobile',
            ],

            'status' => [
                'nullable',
                Rule::in([
                    'ok',
                    'delinquent',
                    'due',
                    'setup',
                    'for_reading',
                    'disconnected',
                ]),
            ],

            'account_type' => [
                'required',
                Rule::in([
                    'residential',
                    'commercial',
                    'industrial',
                    'special_use',
                ]),
            ],

            'barangay' => ['required', 'string', 'max:255'],
            'starting_meter' => ['required', 'numeric', 'min:0'],
            'billing_date' => ['required', 'integer', 'min:1', 'max:31'],
            'previous_reading' => ['required', 'numeric', 'min:0'],
        ]);

        return DB::transaction(function () use ($validated) {
            $accountNumber = trim($validated['account_number']);
            $meterNo = isset($validated['meter_no']) && $validated['meter_no'] !== ''
                ? trim($validated['meter_no'])
                : null;

            $mobile = trim($validated['mobile']);
            $defaultPassword = substr($accountNumber, -5);

            // Important: allow 0 as valid starting meter
            $hasStartingMeter =
                array_key_exists('starting_meter', $validated) &&
                $validated['starting_meter'] !== null &&
                $validated['starting_meter'] !== '';

            // Important: allow 0 as valid previous reading,
            // but store null if blank/not filled up
            $hasPreviousReading =
                array_key_exists('previous_reading', $validated) &&
                $validated['previous_reading'] !== null &&
                $validated['previous_reading'] !== '';

            // If starting_meter is blank, force status to setup
            $status = $hasStartingMeter
                ? ($validated['status'] ?? 'for_reading')
                : 'setup';

            $user = User::create([
                'name' => $validated['account_name'],
                'account_name' => $validated['account_name'],
                'account_number' => $accountNumber,

                // New field
                'meter_no' => $meterNo,

                'mobile' => $mobile,
                'account_type' => $validated['account_type'],
                'barangay' => $validated['barangay'],

                'starting_meter' => $hasStartingMeter
                    ? $validated['starting_meter']
                    : null,

                'billing_date' => $validated['billing_date'],

                'previous_reading' => $hasPreviousReading
                    ? $validated['previous_reading']
                    : null,

                'status' => $status,
                'status_badges' => [$status],
                'role' => 'user',
                'password' => Hash::make($defaultPassword),
                'email' => $mobile . '@bacong-water.local',
            ]);

            return response()->json([
                'message' => 'New application created successfully.',
                'user' => $user,
                'default_password' => $defaultPassword,
            ], 201);
        });
    }
}