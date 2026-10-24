/**
 * Place search and "pick on the map" for form fields, using Google Maps.
 *
 * Without an API key (services.google_maps.key) the component stays disabled and the
 * field is an ordinary text box. The text the person types or picks is what gets saved;
 * the coordinates are saved next to it when a place was chosen from the list or the map.
 */

const NAIROBI = { lat: -1.286389, lng: 36.817223 };

let mapsLoading = null;

function loadGoogleMaps(key) {
    if (window.google?.maps?.importLibrary) {
        return Promise.resolve();
    }

    mapsLoading ??= new Promise((resolve, reject) => {
        window.__tlMapsReady = resolve;
        window.gm_authFailure = () => document.dispatchEvent(new CustomEvent('tl-maps-failed'));

        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&loading=async&v=weekly&callback=__tlMapsReady`;
        script.async = true;
        script.onerror = () => {
            mapsLoading = null;
            reject(new Error('Google Maps did not load.'));
        };
        document.head.appendChild(script);
    });

    return mapsLoading;
}

function plain(position) {
    return {
        lat: typeof position.lat === 'function' ? position.lat() : position.lat,
        lng: typeof position.lng === 'function' ? position.lng() : position.lng,
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('placePicker', (config) => ({
        enabled: Boolean(config.key),
        open: false,
        mapOpen: false,
        suggestions: [],
        active: -1,
        status: '',
        sessionToken: null,
        timer: null,
        settingValue: false,
        map: null,
        marker: null,

        init() {
            if (!this.enabled) {
                return;
            }

            document.addEventListener('tl-maps-failed', () => {
                this.enabled = false;
                this.open = false;
                this.mapOpen = false;
            });

            const input = this.$refs.input;
            input.addEventListener('input', () => this.typed());
            input.addEventListener('keydown', (event) => this.keys(event));
            input.addEventListener('blur', () => setTimeout(() => (this.open = false), 150));
            input.addEventListener('focus', () => {
                if (this.suggestions.length) {
                    this.open = true;
                }
            });
        },

        typed() {
            if (this.settingValue) {
                return;
            }

            // The text no longer matches the place that was picked, so forget its coordinates.
            this.$wire.$set(config.latPath, '', false);
            this.$wire.$set(config.lngPath, '', false);

            clearTimeout(this.timer);
            const text = this.$refs.input.value.trim();

            if (text.length < 3) {
                this.suggestions = [];
                this.open = false;
                this.status = '';

                return;
            }

            this.timer = setTimeout(() => this.search(text), 250);
        },

        async search(text) {
            try {
                await loadGoogleMaps(config.key);
                const { AutocompleteSuggestion, AutocompleteSessionToken } = await google.maps.importLibrary('places');
                this.sessionToken ??= new AutocompleteSessionToken();

                const { suggestions } = await AutocompleteSuggestion.fetchAutocompleteSuggestions({
                    input: text,
                    sessionToken: this.sessionToken,
                    includedRegionCodes: config.country ? [config.country] : undefined,
                });

                if (this.$refs.input.value.trim() !== text) {
                    return;
                }

                this.suggestions = suggestions.map((suggestion, index) => ({
                    id: index,
                    main: suggestion.placePrediction.mainText?.text ?? suggestion.placePrediction.text.text,
                    secondary: suggestion.placePrediction.secondaryText?.text ?? '',
                    prediction: suggestion.placePrediction,
                }));
                this.active = -1;
                this.open = this.suggestions.length > 0;
                this.status = this.suggestions.length ? '' : 'No places found. Try another name, or pick it on the map.';
            } catch (error) {
                console.warn(error);
                this.suggestions = [];
                this.open = false;
                this.status = 'Place search is not available right now. Type the place, or pick it on the map.';
            }
        },

        async choose(item) {
            try {
                const place = item.prediction.toPlace();
                await place.fetchFields({ fields: ['displayName', 'formattedAddress', 'location'] });

                const name = place.displayName ?? item.main;
                const address = place.formattedAddress ?? item.secondary;
                const text = address && !address.startsWith(name) ? `${name}, ${address}` : address || name;
                const position = plain(place.location);

                this.setValue(text, position.lat, position.lng);
                this.sessionToken = null;
                this.suggestions = [];
                this.open = false;
                this.status = '';

                if (this.map) {
                    this.showOnMap(position);
                }
            } catch (error) {
                console.warn(error);
                this.status = 'Could not read that place. Pick another, or choose it on the map.';
            }
        },

        keys(event) {
            if (!this.open || !this.suggestions.length) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.active = (this.active + 1) % this.suggestions.length;
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.active = (this.active - 1 + this.suggestions.length) % this.suggestions.length;
            } else if (event.key === 'Enter' && this.active >= 0) {
                event.preventDefault();
                this.choose(this.suggestions[this.active]);
            } else if (event.key === 'Escape') {
                this.open = false;
            }
        },

        setValue(text, lat, lng) {
            const input = this.$refs.input;

            this.settingValue = true;
            input.value = text.slice(0, 190);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            this.settingValue = false;

            this.$wire.$set(config.latPath, lat.toFixed(7), false);
            this.$wire.$set(config.lngPath, lng.toFixed(7), false);
        },

        async toggleMap() {
            this.mapOpen = !this.mapOpen;

            if (!this.mapOpen) {
                return;
            }

            try {
                await loadGoogleMaps(config.key);
                const { Map } = await google.maps.importLibrary('maps');
                const { AdvancedMarkerElement } = await google.maps.importLibrary('marker');

                const lat = parseFloat(this.$wire.$get(config.latPath));
                const lng = parseFloat(this.$wire.$get(config.lngPath));
                const has = !Number.isNaN(lat) && !Number.isNaN(lng);
                const start = has ? { lat, lng } : NAIROBI;

                if (!this.map) {
                    this.map = new Map(this.$refs.map, {
                        center: start,
                        zoom: has ? 15 : 12,
                        mapId: 'DEMO_MAP_ID',
                        gestureHandling: 'cooperative',
                        streetViewControl: false,
                        mapTypeControl: false,
                        fullscreenControl: false,
                    });
                    this.marker = new AdvancedMarkerElement({ map: has ? this.map : null, position: start, gmpDraggable: true });

                    this.map.addListener('click', (event) => this.pickOnMap(plain(event.latLng)));
                    this.marker.addListener('dragend', () => this.pickOnMap(plain(this.marker.position)));
                } else if (has) {
                    this.showOnMap(start);
                }
            } catch (error) {
                console.warn(error);
                this.mapOpen = false;
                this.status = 'The map is not available right now. Type the place instead.';
            }
        },

        showOnMap(position) {
            this.map.setCenter(position);
            this.map.setZoom(15);
            this.marker.position = position;
            this.marker.map = this.map;
        },

        async pickOnMap(position) {
            this.showOnMap(position);
            this.status = 'Finding the place name…';

            let text = `${position.lat.toFixed(5)}, ${position.lng.toFixed(5)}`;

            try {
                const { Geocoder } = await google.maps.importLibrary('geocoding');
                const { results } = await new Geocoder().geocode({ location: position });
                const named = results.find((result) => !result.types.includes('plus_code')) ?? results[0];
                text = named?.formatted_address ?? text;
            } catch (error) {
                console.warn(error);
            }

            this.setValue(text, position.lat, position.lng);
            this.status = '';
        },
    }));
});
