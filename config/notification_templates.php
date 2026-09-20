<?php

/*
|--------------------------------------------------------------------------
| SMS Message Templates
|--------------------------------------------------------------------------
|
| The wording of every SMS in one place, so it can be changed without
| touching any business logic. {placeholders} are filled in by
| App\Services\MessageTemplates.
|
| Keep them short: SMS is billed per 160-character segment, and a person on
| a basic phone is helped by brevity anyway.
|
*/

return [

    // {link} is the patient's own tracking page.
    'registered' => '{facility}: Hi {name}, your queue number is {number}. Track your visit: {link}',

    'registered_without_link' => '{facility}: Hi {name}, your queue number is {number}.',

    'called' => "{facility}: It's your turn - please proceed to {department}.",

    'almost_turn' => "{facility}: You're almost up in {department}. Please be ready.",

    'transferred' => "{facility}: You've been moved to {department}. Your number: {code}.",

    // {doctor} is the new doctor; {code} the patient's number in that doctor's line.
    'doctor_changed' => '{facility}: Your assigned doctor has changed to {doctor}. Your number: {code}.',

    // Someone at home asked for a place and was accepted. {window} is when to arrive; {link} is their tracking page.
    'remote_accepted' => "{facility}: You're in {doctor}'s queue ({code}). Please arrive {window}. Track: {link}",

    'remote_accepted_without_doctor' => "{facility}: You're in the queue ({code}). Please arrive {window}. Track: {link}",

    // {reason} is empty, or a short sentence the facility gave.
    'remote_declined' => "{facility}: Sorry, we couldn't accept your queue request.{reason} Please visit us or try again later.",

    // For a patient still at home when they are among the next to be called.
    'almost_turn_remote' => "{facility}: You're almost up in {department}. Please come to the facility now.",

    'completed' => '{facility}: Your visit is complete. Thank you for choosing {facility}.',

];
