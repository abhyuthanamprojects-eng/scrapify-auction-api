<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\GatePass;
use App\Models\InspectionBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InspectionController extends Controller
{
    public function index(string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $bookings = InspectionBooking::where('auction_id', $auction->id)
            ->with(['vendor', 'gatePass'])
            ->orderBy('slot_date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $bookings,
        ]);
    }

    public function book(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $user = $request->user();
        $vendor = $user->vendor;

        if (! $vendor) {
            return response()->json(['success' => false, 'message' => 'Vendor profile required to book an inspection.'], 403);
        }

        $validated = $request->validate([
            'visitor_name' => 'required|string|max:120',
            'visitor_mobile' => 'required|string|max:20',
            'visitor_govt_id' => 'required|string|max:40',
            'vehicle_number' => 'nullable|string|max:30',
            'number_of_visitors' => 'nullable|integer|min:1|max:10',
            'slot_date' => 'required|date',
            'slot_time' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $bookingCode = 'INS-'.now()->year.'-'.str_pad((string) (InspectionBooking::count() + 1), 4, '0', STR_PAD_LEFT);

        $booking = InspectionBooking::create([
            'code' => $bookingCode,
            'auction_id' => $auction->id,
            'vendor_id' => $vendor->id,
            'user_id' => $user->id,
            'visitor_name' => $validated['visitor_name'],
            'visitor_mobile' => $validated['visitor_mobile'],
            'visitor_govt_id' => $validated['visitor_govt_id'],
            'vehicle_number' => $validated['vehicle_number'] ?? null,
            'number_of_visitors' => $validated['number_of_visitors'] ?? 1,
            'slot_date' => $validated['slot_date'],
            'slot_time' => $validated['slot_time'],
            'status' => 'confirmed',
            'notes' => $validated['notes'] ?? null,
        ]);

        // Auto-generate Gate Pass with QR Token
        $passNumber = 'GP-'.now()->year.'-'.str_pad((string) (GatePass::count() + 1), 4, '0', STR_PAD_LEFT);
        $qrToken = 'SCRAPIFY-GP-'.$auction->code.'-'.strtoupper(Str::random(6));

        $gatePass = GatePass::create([
            'pass_number' => $passNumber,
            'qr_token' => $qrToken,
            'type' => 'inspection',
            'auction_id' => $auction->id,
            'inspection_booking_id' => $booking->id,
            'vendor_id' => $vendor->id,
            'visitor_name' => $validated['visitor_name'],
            'company_name' => $vendor->company_name,
            'facility_name' => $auction->plant ?? $auction->location ?? 'Main Yard',
            'vehicle_number' => $validated['vehicle_number'] ?? null,
            'valid_from' => now()->startOfDay(),
            'valid_until' => now()->addDays(7)->endOfDay(),
            'status' => 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Inspection booked and Gate Pass generated.',
            'data' => [
                'booking' => $booking,
                'gate_pass' => $gatePass,
            ],
        ], 201);
    }

    public function verifyGatePass(string $qrToken): JsonResponse
    {
        $pass = GatePass::where('qr_token', $qrToken)
            ->with(['auction', 'booking', 'order', 'vendor'])
            ->first();

        if (! $pass) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or unverified Gate Pass QR token.',
                'error' => ['code' => 'GATE_PASS_NOT_FOUND'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $pass,
        ]);
    }

    public function scanGatePass(Request $request, string $qrToken): JsonResponse
    {
        $pass = GatePass::where('qr_token', $qrToken)->firstOrFail();
        $user = $request->user();

        if ($pass->status === 'used') {
            return response()->json([
                'success' => false,
                'message' => 'Gate Pass has already been used on '.$pass->scanned_at,
                'error' => ['code' => 'GATE_PASS_ALREADY_USED'],
            ], 400);
        }

        $pass->update([
            'status' => 'used',
            'scanned_at' => now(),
            'scanned_by' => $user?->id,
        ]);

        if ($pass->booking) {
            $pass->booking->update(['status' => 'attended']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Gate Pass verified and scanned at plant gate.',
            'data' => $pass,
        ]);
    }
}
