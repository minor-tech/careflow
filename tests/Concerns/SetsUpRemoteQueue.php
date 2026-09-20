<?php

namespace Tests\Concerns;

use App\Enums\DepartmentType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Service;
use App\Models\User;

/**
 * A facility that takes remote queue requests, with a Consultation department
 * that gives each patient their own doctor, two doctors on duty, and a
 * receptionist and admin. The clock is fixed at 10:00 in the clinic's time so
 * "when can you arrive" and cut-offs are the same on every run.
 */
trait SetsUpRemoteQueue
{
    protected Facility $facility;

    protected Department $reception;

    protected Department $consultation;

    protected Service $general;

    protected User $wanjiku;

    protected User $kamau;

    protected User $receptionist;

    protected User $admin;

    protected function setUpRemoteQueue(): void
    {
        $this->travelTo(now(config('careflow.timezone'))->setTime(10, 0));

        $this->facility = Facility::factory()->create([
            'name' => 'Upendo Clinic',
            'slug' => 'upendo',
            'county' => 'Muranga',
            'sub_county' => 'Kiharu',
            'notification_channels' => ['sms'],
            'sms_sender_id' => 'UPENDO',
            'remote_queue_enabled' => true,
            'self_checkin_enabled' => true,
        ]);
        $this->reception = Department::factory()->for($this->facility)->create(['name' => 'Reception', 'type' => DepartmentType::Reception]);
        $this->consultation = Department::factory()->for($this->facility)->assigningDoctors()->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->general = Service::factory()->for($this->consultation)->create(['name' => 'General Medicine']);
        $this->wanjiku = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Wanjiku Mwangi', 'department_id' => $this->consultation->id, 'service_id' => $this->general->id]);
        $this->kamau = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Kamau Njoroge', 'department_id' => $this->consultation->id, 'service_id' => $this->general->id]);
        $this->receptionist = User::factory()->for($this->facility)->receptionist()->create(['department_id' => $this->reception->id]);
        $this->admin = User::factory()->for($this->facility)->create();
    }
}
