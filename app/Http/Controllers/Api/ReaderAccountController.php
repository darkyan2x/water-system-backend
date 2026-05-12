<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;

class ReaderAccountController extends Controller
{
    public function index(Request $request)
    {
        // customers are users where role = 'user'
        $q = User::query()
            ->where('role', 'user')
            ->select([
                'id',
                'name',
                'account_number',
                'barangay',
                'purok',
            ])
            ->orderBy('barangay')
            ->orderBy('purok')
            ->orderBy('name');

        return response()->json([
            'data' => $q->get(),
        ]);
    }
}
