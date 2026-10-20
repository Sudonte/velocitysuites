<?php

namespace App\Http\Controllers;

use App\Support\MobileApp;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppDownloadController extends Controller
{
    /**
     * Sends the Android APK as a download (Content-Disposition: attachment, APK
     * MIME type), so a phone saves it instead of showing the bytes. Served
     * through Laravel rather than a /downloads URL because the live web root
     * only exposes css, js and images from this app's public folder.
     */
    public function __invoke(): BinaryFileResponse
    {
        abort_unless(MobileApp::isAvailable(), 404);

        return response()->download(MobileApp::path(), MobileApp::downloadName(), [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
