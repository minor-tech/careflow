<?php

namespace App\Models;

use Database\Factories\ContactMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A message someone sent through the public contact form.
 */
#[Fillable(['name', 'contact', 'facility_name', 'message'])]
class ContactMessage extends Model
{
    /** @use HasFactory<ContactMessageFactory> */
    use HasFactory;

    /**
     * There is only a created_at.
     */
    public const UPDATED_AT = null;
}
