<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Support\GuestAccountLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lookup behind the optional "Guest account" field on the receptionist's create / walk-in forms. */
class GuestAccountController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        return response()->json(GuestAccountLink::search((string) $request->get('q', '')));
    }
}
