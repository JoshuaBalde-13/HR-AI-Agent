<?php

namespace App\Http\Controllers;

use App\Models\RobotEvent;
use Illuminate\Http\JsonResponse;

class RobotEventController extends Controller
{
    public function markRead(RobotEvent $robotEvent): JsonResponse
    {
        $robotEvent->update(['is_read' => true]);

        return response()->json(['ok' => true]);
    }
}