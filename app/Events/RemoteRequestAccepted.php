<?php

namespace App\Events;

use App\Models\RemoteRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A request from home was accepted: there is now a visit holding a place in the
 * line, and the requester is owed the news. Listeners react once the
 * surrounding transaction has committed.
 */
class RemoteRequestAccepted
{
    use Dispatchable;

    public function __construct(public RemoteRequest $request) {}
}
