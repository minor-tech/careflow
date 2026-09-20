<?php

namespace App\Events;

use App\Models\RemoteRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A queue request was declined, and the requester is owed the news. Listeners
 * react once the surrounding transaction has committed.
 */
class RemoteRequestDeclined
{
    use Dispatchable;

    public function __construct(public RemoteRequest $request) {}
}
