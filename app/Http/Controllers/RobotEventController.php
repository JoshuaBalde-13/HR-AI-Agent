<?php

namespace App\Http\Controllers;

use App\Models\RobotEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RobotEventController extends Controller
{
    public function markRead(
        Request $request,
        RobotEvent $robotEvent
    ): JsonResponse {
        $role = $request->attributes->get('hris.role');

        abort_unless(
            is_string($role) && $robotEvent->role === $role,
            403,
            'You cannot modify another role’s robot event.'
        );

        $robotEvent->update([
            'is_read' => true,
        ]);

        return response()->json([
            'ok' => true,
        ]);
    }
}