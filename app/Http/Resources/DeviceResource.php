<?php

namespace App\Http\Resources;

use App\Models\EmployeeDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeDevice
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EmployeeDevice $device */
        $device = $this->resource;

        return [
            'device_id' => $device->device_id,
            'model' => $device->model,
            'platform' => $device->platform,
            'bound_at' => $device->bound_at->toIso8601String(),
        ];
    }
}
