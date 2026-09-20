<?php

namespace App\Http\Requests;

/**
 * The shorter form for someone already in the building: the same name, phone
 * and service, and no "when can you arrive", because they are here now.
 */
class StoreSelfCheckinRequest extends StoreRemoteRequestRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function arrivalRules(): array
    {
        return [];
    }
}
