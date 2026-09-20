<?php

namespace App\Support;

class Counties
{
    /**
     * The 47 counties of Kenya, in county-code order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'Mombasa', 'Kwale', 'Kilifi', 'Tana River', 'Lamu', 'Taita-Taveta',
            'Garissa', 'Wajir', 'Mandera', 'Marsabit', 'Isiolo', 'Meru',
            'Tharaka-Nithi', 'Embu', 'Kitui', 'Machakos', 'Makueni', 'Nyandarua',
            'Nyeri', 'Kirinyaga', "Murang'a", 'Kiambu', 'Turkana', 'West Pokot',
            'Samburu', 'Trans Nzoia', 'Uasin Gishu', 'Elgeyo-Marakwet', 'Nandi', 'Baringo',
            'Laikipia', 'Nakuru', 'Narok', 'Kajiado', 'Kericho', 'Bomet',
            'Kakamega', 'Vihiga', 'Bungoma', 'Busia', 'Siaya', 'Kisumu',
            'Homa Bay', 'Migori', 'Kisii', 'Nyamira', 'Nairobi',
        ];
    }
}
