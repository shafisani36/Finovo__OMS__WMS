<?php

namespace App\Services;

use App\Models\Warehouse;

class WarehouseReadinessService
{
    /**
     * Authoritatively evaluate whether a warehouse meets all operational activation prerequisites.
     *
     * @return array{
     *     is_ready: bool,
     *     score: int,
     *     status: string,
     *     issues: array<int, array{category: string, code: string, severity: string, message: string, remediation: string}>,
     *     passed_checks: array<int, string>
     * }
     */
    public function evaluate(Warehouse $warehouse): array
    {
        $issues = [];
        $passedChecks = [];

        // 1. Basic Information Verification
        if (empty(trim((string) $warehouse->name))) {
            $issues[] = [
                'category' => 'basic_info',
                'code' => 'MISSING_NAME',
                'severity' => 'error',
                'message' => 'Warehouse name is required.',
                'remediation' => 'Provide a recognizable warehouse name in Step 1.',
            ];
        } else {
            $passedChecks[] = 'Warehouse name is set: '.$warehouse->name;
        }

        if (empty(trim((string) $warehouse->code))) {
            $issues[] = [
                'category' => 'basic_info',
                'code' => 'MISSING_CODE',
                'severity' => 'error',
                'message' => 'Warehouse code is required and must be unique.',
                'remediation' => 'Specify a unique code (e.g. WH-DXB-01).',
            ];
        } else {
            $passedChecks[] = 'Warehouse identifier code is valid: '.$warehouse->code;
        }

        // 2. Regional & Address Settings
        $validTimezones = timezone_identifiers_list();
        if (empty($warehouse->timezone) || ! in_array($warehouse->timezone, $validTimezones, true)) {
            $issues[] = [
                'category' => 'regional',
                'code' => 'INVALID_TIMEZONE',
                'severity' => 'error',
                'message' => 'A valid IANA timezone is mandatory for operational scheduling.',
                'remediation' => 'Select a valid timezone such as UTC, America/New_York, or Asia/Karachi in Step 2.',
            ];
        } else {
            $passedChecks[] = 'Operating timezone configured: '.$warehouse->timezone;
        }

        if (empty(trim((string) ($warehouse->city ?? $warehouse->address)))) {
            $issues[] = [
                'category' => 'regional',
                'code' => 'INCOMPLETE_ADDRESS',
                'severity' => 'warning',
                'message' => 'Physical facility address is incomplete.',
                'remediation' => 'Enter the city, street address, and country for logistics routing.',
            ];
        } else {
            $passedChecks[] = 'Physical location details recorded.';
        }

        if (empty(trim((string) $warehouse->contact_email))) {
            $issues[] = [
                'category' => 'regional',
                'code' => 'MISSING_CONTACT_EMAIL',
                'severity' => 'warning',
                'message' => 'No facility operations email is designated for dispatch alerts.',
                'remediation' => 'Provide a primary contact email in Step 2.',
            ];
        } else {
            $passedChecks[] = 'Operations contact email designated: '.$warehouse->contact_email;
        }

        // 3. Physical Layout & Locations
        $totalLocations = $warehouse->locations()->count();
        $pickingLocations = $warehouse->locations()->where('picking_eligible', true)->count();
        $putawayLocations = $warehouse->locations()->where('putaway_eligible', true)->count();

        if ($totalLocations === 0) {
            $issues[] = [
                'category' => 'physical_layout',
                'code' => 'NO_LOCATIONS',
                'severity' => 'error',
                'message' => 'Facility has no physical warehouse zones or bins.',
                'remediation' => 'Configure zones and aisles or generate a starter layout in Step 3.',
            ];
        } else {
            $passedChecks[] = "{$totalLocations} storage location(s) defined.";

            if ($pickingLocations === 0) {
                $issues[] = [
                    'category' => 'physical_layout',
                    'code' => 'NO_PICKING_LOCATIONS',
                    'severity' => 'error',
                    'message' => 'No locations are marked as picking-eligible.',
                    'remediation' => 'Enable picking eligibility on at least one forward picking or bulk zone.',
                ];
            } else {
                $passedChecks[] = "{$pickingLocations} picking-eligible location(s) active.";
            }

            if ($putawayLocations === 0) {
                $issues[] = [
                    'category' => 'physical_layout',
                    'code' => 'NO_PUTAWAY_LOCATIONS',
                    'severity' => 'error',
                    'message' => 'No locations are marked as putaway-eligible.',
                    'remediation' => 'Enable putaway eligibility on receiving, bulk storage, or bin locations.',
                ];
            } else {
                $passedChecks[] = "{$putawayLocations} putaway-eligible location(s) active.";
            }
        }

        // 4. Inventory & Tracking Policies
        $invPolicies = $warehouse->inventory_policies ?? [];
        if (empty($invPolicies)) {
            $issues[] = [
                'category' => 'inventory_policies',
                'code' => 'DEFAULT_POLICIES_UNCONFIRMED',
                'severity' => 'warning',
                'message' => 'Inventory policies are running on default system fallbacks.',
                'remediation' => 'Review and confirm reservation, picking strategy (FIFO/FEFO), and tracking policies in Step 4.',
            ];
        } else {
            $passedChecks[] = 'Inventory tracking policies configured and verified.';
        }

        // 5. Staff & Access
        $staffCount = $warehouse->memberships()->where('status', 'active')->count();
        if ($staffCount === 0) {
            $issues[] = [
                'category' => 'staff_access',
                'code' => 'NO_ASSIGNED_STAFF',
                'severity' => 'warning',
                'message' => 'No warehouse operators or managers are assigned.',
                'remediation' => 'Assign at least one staff member or supervisor in Step 6.',
            ];
        } else {
            $passedChecks[] = "{$staffCount} active staff member(s) assigned.";
        }

        // Calculate Readiness Metrics
        $hasErrors = collect($issues)->contains('severity', 'error');
        $errorCount = collect($issues)->where('severity', 'error')->count();
        $warningCount = collect($issues)->where('severity', 'warning')->count();

        $maxScore = 100;
        $penalty = ($errorCount * 25) + ($warningCount * 10);
        $score = max(0, min($maxScore, $maxScore - $penalty));

        $status = $hasErrors ? Warehouse::STATUS_SETUP_INCOMPLETE : Warehouse::STATUS_READY_FOR_ACTIVATION;
        if ($warehouse->status === Warehouse::STATUS_ACTIVE) {
            $status = Warehouse::STATUS_ACTIVE;
        }

        return [
            'is_ready' => ! $hasErrors,
            'score' => $score,
            'status' => $status,
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'issues' => $issues,
            'passed_checks' => $passedChecks,
        ];
    }
}
