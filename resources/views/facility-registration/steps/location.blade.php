<x-wizard-layout :steps="$steps" :step="$step" :completed="$completed">
    <form method="POST" action="{{ route('facility.register.save', $step) }}" class="mt-6 space-y-5">
        @csrf

        <x-field name="county" label="County">
            <x-select id="county" name="county" :options="$options['counties']" :selected="$value('county')" placeholder="Select a county" required autofocus />
        </x-field>

        <x-field name="sub_county" label="Sub-county or town">
            <x-text-input id="sub_county" name="sub_county" type="text" :value="$value('sub_county')" required autocomplete="address-level2" />
        </x-field>

        <x-field name="address" label="Physical address or landmark" hint="Something a patient could use to find you, such as opposite the Total petrol station.">
            <x-text-input id="address" name="address" type="text" :value="$value('address')" required autocomplete="street-address" />
        </x-field>

        <div class="space-y-3" x-data="mapPicker({ lat: @js($value('latitude')), lng: @js($value('longitude')) })">
            <div>
                <p class="text-sm font-medium">GPS coordinates <span class="font-normal text-ink/60">(optional)</span></p>
                <p class="text-sm text-ink/60">Tap the map to drop a pin on your facility, or use your current location.</p>
            </div>

            <div x-ref="map" class="h-64 w-full overflow-hidden rounded-md border border-line bg-white" role="application" aria-label="Map for choosing your facility location"></div>
            <p x-show="mapError" x-cloak class="text-sm text-ink/60">The map could not load. You can type the coordinates below instead.</p>

            <div class="flex flex-wrap items-center gap-4">
                <x-outline-button type="button" x-on:click="locate()">Use my current location</x-outline-button>
                <button type="button" class="btn-outline btn-sm" x-show="lat !== '' || lng !== ''" x-cloak x-on:click="clear()">Clear pin</button>
            </div>
            <p x-show="locateError" x-cloak x-text="locateError" class="text-sm text-danger"></p>

            <div class="grid grid-cols-2 gap-3">
                <x-field name="latitude" label="Latitude">
                    <x-text-input id="latitude" name="latitude" type="text" inputmode="decimal" x-model="lat" x-on:change="placeFromFields()" />
                </x-field>
                <x-field name="longitude" label="Longitude">
                    <x-text-input id="longitude" name="longitude" type="text" inputmode="decimal" x-model="lng" x-on:change="placeFromFields()" />
                </x-field>
            </div>
        </div>

        <x-wizard-actions :step="$step" />
    </form>
</x-wizard-layout>
