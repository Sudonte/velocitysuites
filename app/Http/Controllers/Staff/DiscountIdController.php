<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use App\Support\DiscountIdInfo;
use Illuminate\Support\Facades\Storage;

/**
 * Streams a guest's uploaded discount ID to the Billing / Check-out page. Only reachable
 * through the role:receptionist,admin route group - an ID card is a photo of a government
 * document, so it is never served from a public URL (it lives on the private 'local'
 * disk) and anyone who is not a logged-in receptionist or system administrator is refused
 * by the role middleware before this runs.
 */
class DiscountIdController extends Controller
{
    public function show(Billing $billing)
    {
        $info = DiscountIdInfo::forBilling($billing);

        if (! $info['has_id']) {
            abort(404);
        }

        // private + no-store: never cached by a shared proxy or kept after logout.
        return Storage::disk('local')->response($info['path'], null, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
