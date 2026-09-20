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

    'completed' => '{facility}: Your visit is complete. Thank you for choosing {facility}.',

];
