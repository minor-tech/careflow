<?php

namespace App\Actions;

use App\Enums\DepartmentType;
use App\Enums\FacilityStatus;
use App\Enums\UserRole;
use App\Models\Facility;
use Illuminate\Support\Facades\DB;

class RegisterFacility
{
    public function __construct(private CreateStaffMember $createStaffMember) {}

    /**
     * Persist a completed registration draft: the facility (pending review),
     * its departments, the admin account and any invited staff, all or nothing.
     *
     * @param  array<string, mixed>  $data  A validated draft; admin_password_hash is already hashed.
     */
    public function handle(array $data): Facility
    {
        return DB::transaction(function () use ($data): Facility {
            $acceptedAt = now();

            $facility = Facility::create([
                'name' => $data['name'],
                'facility_type' => $data['facility_type'],
                'license_number' => $data['license_number'],
                'ownership_type' => $data['ownership_type'] ?? null,
                'county' => $data['county'],
                'sub_county' => $data['sub_county'],
                'address' => $data['address'],
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'phone' => $data['phone'],
                'alt_phone' => $data['alt_phone'] ?? null,
                'email' => $data['email'],
                'website' => $data['website'] ?? null,
                'operating_days' => $data['operating_days'],
                'is_24hr' => $data['is_24hr'],
                'opens_at' => $data['is_24hr'] ? null : $data['opens_at'],
                'closes_at' => $data['is_24hr'] ? null : $data['closes_at'],
                'doctors_count' => $data['doctors_count'] ?? null,
                'consultation_rooms' => $data['consultation_rooms'] ?? null,
                'notification_channels' => $data['notification_channels'],
                'sms_sender_id' => $data['sms_sender_id'] ?? null,
                'data_role' => $data['data_role'],
                'odpc_registration_no' => $data['odpc_registration_no'] ?? null,
                'dpa_accepted_at' => $acceptedAt,
                'patient_consent_confirmed_at' => $acceptedAt,
                'terms_accepted_at' => $acceptedAt,
                'privacy_accepted_at' => $acceptedAt,
                'signature_name' => $data['signature'],
                'status' => FacilityStatus::PendingReview,
            ]);

            $departments = [];

            foreach ($data['departments'] as $type) {
                $departments[$type] = $facility->departments()->create([
                    'name' => DepartmentType::from($type)->label(),
                    'type' => $type,
                ]);
            }

            $facility->users()->create([
                'name' => $data['admin_name'],
                'title' => $data['admin_title'],
                'phone' => $data['admin_phone'],
                'email' => $data['admin_email'],
                'password' => $data['admin_password_hash'],
                'role' => UserRole::Admin,
                'two_factor_enabled' => $data['two_factor_enabled'],
            ]);

            foreach ($data['staff'] ?? [] as $member) {
                $this->createStaffMember->handle($facility, [
                    'name' => $member['name'],
                    'email' => $member['email'],
                    'phone' => $member['phone'],
                    'role' => $member['role'],
                    'department_id' => isset($member['department']) ? $departments[$member['department']]->id : null,
                ]);
            }

            return $facility;
        });
    }
}
