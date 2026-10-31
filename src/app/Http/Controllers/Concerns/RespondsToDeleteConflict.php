<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Exceptions\BusinessRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait RespondsToDeleteConflict
{
    /**
     * Handle business rule delete conflicts in an Inertia-compatible manner:
     * - JSON requests receive HTTP 409 Conflict.
     * - Web/Inertia requests receive standard back redirect (HTTP 303 on DELETE) with error flash.
     */
    protected function respondToDeleteConflict(Request $request, BusinessRuleException $e): JsonResponse|RedirectResponse
    {
        $message = 'Tidak dapat menghapus data: '.$e->getMessage();

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => ['form' => [$message]],
            ], 409);
        }

        return back()->with('error', $message);
    }
}
