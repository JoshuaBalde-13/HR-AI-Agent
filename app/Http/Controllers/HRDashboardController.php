<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\EmployeeDocument;
use App\Models\RobotEvent;

class HRDashboardController extends Controller
{
    public function index()
    {
        $submittedCount = Application::where('status', '!=', 'draft')->count();

        $todayCount = Application::where('status', '!=', 'draft')
            ->whereDate('submitted_at', today())
            ->count();

        // Everything HR still has to act on:
        // submitted, under review, interview scheduled, interview completed.
        $pendingReviewCount = Application::pending()->count();

        $inProgressCount = Application::where('status', 'draft')->count();

        $expiredCount = EmployeeDocument::whereDate(
            'expiry_date',
            '<',
            today()
        )->count();

        $expiringSoonCount = EmployeeDocument::whereBetween(
            'expiry_date',
            [today(), today()->addDays(30)]
        )->count();

        $unreadAlerts = RobotEvent::where('role', 'HR')
            ->where('is_read', false)
            ->count();

        $recentApplications = Application::with('applicant')
            ->where('status', '!=', 'draft')
            ->latest('submitted_at')
            ->take(5)
            ->get();

        $expiredDocuments = EmployeeDocument::whereDate(
            'expiry_date',
            '<',
            today()
        )
            ->orderBy('expiry_date')
            ->take(5)
            ->get();

        return view('hr.dashboard', [
            'submittedCount' => $submittedCount,
            'todayCount' => $todayCount,
            'pendingReviewCount' => $pendingReviewCount,
            'inProgressCount' => $inProgressCount,
            'expiredCount' => $expiredCount,
            'expiringSoonCount' => $expiringSoonCount,
            'unreadAlerts' => $unreadAlerts,
            'recentApplications' => $recentApplications,
            'expiredDocuments' => $expiredDocuments,
        ]);
    }

    public function compliance()
    {
        $expired = EmployeeDocument::whereDate('expiry_date', '<', today())
            ->orderBy('expiry_date')
            ->get();

        $expiringSoon = EmployeeDocument::whereBetween(
            'expiry_date',
            [today(), today()->addDays(30)]
        )
            ->orderBy('expiry_date')
            ->get();

        return view('hr.compliance', [
            'expired' => $expired,
            'expiringSoon' => $expiringSoon,
        ]);
    }
}