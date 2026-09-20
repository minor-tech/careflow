import Alpine from 'alpinejs';

window.Alpine = Alpine;

// Password strength meter for the admin account screen. Purely advisory: the
// server enforces the real rules.
Alpine.data('passwordStrength', () => ({
    password: '',
    show: false,

    get score() {
        const password = this.password;

        if (password.length === 0) {
            return 0;
        }

        if (password.length < 8) {
            return 1;
        }

        const points = [
            password.length >= 12,
            /[a-z]/.test(password) && /[A-Z]/.test(password),
            /\d/.test(password),
            /[^A-Za-z0-9]/.test(password),
        ].filter(Boolean).length;

        return Math.min(4, Math.max(2, points + 1));
    },

    get label() {
        return ['', 'Weak', 'Fair', 'Good', 'Strong'][this.score];
    },
}));

// Repeatable "invite a staff member" rows. Server-side validation errors are
// keyed like "staff.0.email" and shown against the matching row.
Alpine.data('staffRows', (initialRows = [], initialErrors = {}) => {
    let counter = 0;
    const withKey = (row = {}) => ({
        key: ++counter,
        name: row.name ?? '',
        email: row.email ?? '',
        phone: row.phone ?? '',
        role: row.role ?? '',
        department: row.department ?? '',
    });

    return {
        rows: initialRows.map(withKey),
        errors: initialErrors,

        add() {
            this.rows.push(withKey());
        },

        remove(index) {
            this.rows.splice(index, 1);
            // Row numbers shifted, so the old error keys no longer line up.
            this.errors = {};
        },

        error(index, field) {
            return this.errors[`staff.${index}.${field}`]?.[0] ?? '';
        },
    };
});

// A patient's tracking page: every few seconds, fetch the live part of the page
// (already rendered by the server) and swap it in if it changed. It pauses while
// the tab is hidden, catches up the moment it is shown again, and stops for good
// once the visit is over (a visit that is already over when the page opens is never
// polled, so a rating being filled in is never swapped out from under the patient).
// A failed check keeps what is on screen and tries again.
Alpine.data('trackingPage', ({ url, seconds, finished = false }) => ({
    offline: false,
    finished,
    loading: false,
    timer: null,
    lastHtml: null,

    init() {
        this.schedule();

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.refresh();
            }
        });
    },

    schedule() {
        clearTimeout(this.timer);

        if (!this.finished) {
            this.timer = setTimeout(() => this.refresh(), seconds * 1000);
        }
    },

    async refresh() {
        if (this.loading || this.finished) {
            return;
        }

        if (document.hidden) {
            return;
        }

        this.loading = true;
        clearTimeout(this.timer);

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'omit',
            });

            // The link stopped being valid (a new day): show the page that says so.
            if (response.status === 404) {
                window.location.reload();

                return;
            }

            if (!response.ok) {
                throw new Error(`Unexpected status ${response.status}`);
            }

            const data = await response.json();

            if (data.html !== this.lastHtml) {
                this.$refs.live.innerHTML = data.html;
                this.lastHtml = data.html;
            }

            this.finished = data.finished === true;
            this.offline = false;
        } catch (error) {
            this.offline = true;
        } finally {
            this.loading = false;
            this.schedule();
        }
    },
}));

// "Copy link" on the registration screen. The clipboard API only exists on secure
// pages (https or localhost), so where it is missing the text is selected and
// copied the old way instead.
Alpine.data('copyLink', () => ({
    copied: false,

    async copy() {
        const field = this.$refs.link;

        try {
            await navigator.clipboard.writeText(field.value);
        } catch (error) {
            field.select();
            document.execCommand('copy');
        }

        this.copied = true;
        setTimeout(() => (this.copied = false), 2000);
    },
}));

// Leaflet is loaded on demand from cdnjs (with integrity hashes) so the rest of
// the wizard never depends on it. If it fails to load, the latitude/longitude
// fields still work on their own.
const LEAFLET_URL = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/';
let leafletPromise = null;

function loadLeaflet() {
    if (window.L) {
        return Promise.resolve(window.L);
    }

    leafletPromise ??= new Promise((resolve, reject) => {
        const stylesheet = document.createElement('link');
        stylesheet.rel = 'stylesheet';
        stylesheet.href = `${LEAFLET_URL}leaflet.min.css`;
        stylesheet.integrity = 'sha384-c6Rcwz4e4CITMbu/NBmnNS8yN2sC3cUElMEMfP3vqqKFp7GOYaaBBCqmaWBjmkjb';
        stylesheet.crossOrigin = '';
        document.head.append(stylesheet);

        const script = document.createElement('script');
        script.src = `${LEAFLET_URL}leaflet.min.js`;
        script.integrity = 'sha384-NElt3Op+9NBMCYaef5HxeJmU4Xeard/Lku8ek6hoPTvYkQPh3zLIrJP7KiRocsxO';
        script.crossOrigin = '';
        script.onload = () => resolve(window.L);
        script.onerror = () => {
            leafletPromise = null;
            reject(new Error('Leaflet failed to load'));
        };
        document.head.append(script);
    });

    return leafletPromise;
}

const KENYA_CENTRE = [0.0236, 37.9062];

Alpine.data('mapPicker', ({ lat = null, lng = null } = {}) => {
    // Kept out of Alpine's reactive state: Leaflet objects don't survive being proxied.
    let map = null;
    let marker = null;

    return {
        lat: lat ?? '',
        lng: lng ?? '',
        mapError: false,
        locateError: '',

        init() {
            loadLeaflet()
                .then((L) => this.setUpMap(L))
                .catch(() => {
                    this.mapError = true;
                });
        },

        hasPoint() {
            const latitude = parseFloat(this.lat);
            const longitude = parseFloat(this.lng);

            return Number.isFinite(latitude) && Number.isFinite(longitude)
                && Math.abs(latitude) <= 90 && Math.abs(longitude) <= 180;
        },

        setUpMap(L) {
            const start = this.hasPoint() ? [parseFloat(this.lat), parseFloat(this.lng)] : KENYA_CENTRE;

            map = L.map(this.$refs.map).setView(start, this.hasPoint() ? 15 : 6);

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors',
            }).addTo(map);

            map.on('click', (event) => this.setPoint(event.latlng.lat, event.latlng.lng));

            this.placeMarker();
        },

        placeMarker() {
            if (! map) {
                return;
            }

            if (! this.hasPoint()) {
                marker?.remove();
                marker = null;

                return;
            }

            const position = [parseFloat(this.lat), parseFloat(this.lng)];

            if (marker) {
                marker.setLatLng(position);
            } else {
                marker = window.L.marker(position, { draggable: true }).addTo(map);
                marker.on('dragend', () => {
                    const { lat: latitude, lng: longitude } = marker.getLatLng();
                    this.setPoint(latitude, longitude, false);
                });
            }
        },

        setPoint(latitude, longitude, recentre = true) {
            this.lat = latitude.toFixed(6);
            this.lng = longitude.toFixed(6);
            this.locateError = '';
            this.placeMarker();

            if (recentre && map) {
                map.setView([latitude, longitude], Math.max(map.getZoom(), 15));
            }
        },

        // Called when the coordinates are typed by hand.
        placeFromFields() {
            this.placeMarker();

            if (this.hasPoint() && map) {
                map.setView([parseFloat(this.lat), parseFloat(this.lng)], Math.max(map.getZoom(), 15));
            }
        },

        clear() {
            this.lat = '';
            this.lng = '';
            this.placeMarker();
        },

        locate() {
            this.locateError = '';

            if (! navigator.geolocation) {
                this.locateError = 'This device cannot share its location. Tap the map instead.';

                return;
            }

            navigator.geolocation.getCurrentPosition(
                (position) => this.setPoint(position.coords.latitude, position.coords.longitude),
                () => {
                    this.locateError = 'We could not get your location. Allow location access, or tap the map instead.';
                },
                { enableHighAccuracy: true, timeout: 10000 },
            );
        },
    };
});

// Front-desk patient registration. As the phone number is typed, ask the server
// whether this facility already has that patient and, if so, fill in what is
// on record. Only empty fields are filled: anything already typed is left
// alone. The submit guard stops a double tap creating a second visit.
Alpine.data('patientRegistration', ({ lookupUrl, phone = '', name = '', dob = '', gender = '' }) => {
    let request = null;

    return {
        phone: phone ?? '',
        name: name ?? '',
        dob: dob ?? '',
        gender: gender ?? '',
        knownAs: null,
        submitting: false,

        async lookup() {
            // Anything with fewer than 9 digits can't be a full number yet.
            if (this.phone.replace(/\D/g, '').length < 9) {
                this.knownAs = null;

                return;
            }

            request?.abort();
            request = new AbortController();

            try {
                const response = await fetch(`${lookupUrl}?phone=${encodeURIComponent(this.phone)}`, {
                    headers: { Accept: 'application/json' },
                    signal: request.signal,
                });

                if (! response.ok) {
                    return;
                }

                const result = await response.json();

                if (! result.found) {
                    this.knownAs = null;

                    return;
                }

                this.knownAs = result.patient.name;
                this.name ||= result.patient.name;
                this.dob ||= result.patient.dob ?? '';
                this.gender ||= result.patient.gender ?? '';
            } catch {
                // Aborted by a newer keystroke, or offline: registration still works without the lookup.
            }
        },
    };
});

// The staff queue. Every few seconds the board is re-fetched from the same
// address (asking for just the board) and swapped in, so a colleague's Call or
// Complete shows up without a reload. Plain polling for now; real broadcasting
// can replace it later.
Alpine.data('queueBoard', ({ interval = 12000 } = {}) => {
    let last = null;

    const clock = () => new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });

    return {
        offline: false,
        checkedAt: clock(),

        init() {
            setInterval(() => this.refresh(), interval);
            document.addEventListener('visibilitychange', () => this.refresh());

            // A "Send to…" menu closes when you click anywhere else.
            document.addEventListener('click', (event) => {
                this.$refs.board.querySelectorAll('details[open]').forEach((menu) => {
                    if (! menu.contains(event.target)) {
                        menu.open = false;
                    }
                });
            });
        },

        async refresh() {
            const board = this.$refs.board;

            // Nothing to do in a background tab, and never swap the board out
            // from under a button that is being pressed or a menu that is open.
            if (document.hidden || board.querySelector(':active, details[open]')) {
                return;
            }

            try {
                const response = await fetch(window.location.href, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                    credentials: 'same-origin',
                });

                // Redirected means the session ended (or the facility was suspended): go to the login page.
                if (response.redirected) {
                    window.location.reload();

                    return;
                }

                if (! response.ok) {
                    throw new Error(`Queue refresh failed: ${response.status}`);
                }

                const html = await response.text();

                if (html !== last) {
                    board.innerHTML = html;
                    last = html;
                }

                this.offline = false;
                this.checkedAt = clock();
            } catch {
                this.offline = true;
            }
        },
    };
});

Alpine.start();
