<?php

namespace App\Support;

use App\Models\User;

/**
 * A staff account just created, together with the temporary password it was
 * created with. The password is only known here: the database keeps a hash.
 */
final readonly class NewStaffAccount
{
    public function __construct(
        public User $user,
        #[\SensitiveParameter] public string $temporaryPassword,
    ) {}
}
