<?php

namespace Hwkdo\IntranetAppBewerbungen\Http\Controllers\Api;

use Hwkdo\IntranetAppBewerbungen\Jobs\AnalyzeLegacyBewerbungJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LegacyBewerbungenAiController
{
    public function enqueue(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'request_id' => ['required', 'string', 'max:100'],
            'bewerbung_id' => ['required', 'integer', 'min:1'],
            'stelle_id' => ['nullable', 'integer', 'min:1'],
            'cloud_bewerbung_ro' => ['required', 'string', 'max:2048'],
            'cloud_anhang_ro' => ['nullable', 'string', 'max:2048'],
            'triggered_at' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        AnalyzeLegacyBewerbungJob::dispatch($data)->onQueue('default');

        return response()->json([
            'status' => 'queued',
            'request_id' => $data['request_id'],
            'bewerbung_id' => $data['bewerbung_id'],
        ], 202);
    }
}

