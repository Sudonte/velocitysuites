/**
 * Cascading Country -> Region -> Province -> City/Municipality -> Barangay -> Street -> ZIP
 * address widget, matching the Velocity Suites Android app's AddressHierarchyController.java
 * behavior exactly: Philippines drives live PSGC lookups (https://psgc.gitlab.io/api/) for
 * Region/Province/City/Barangay. Any other country hides the entire PH-specific block (Region/
 * Province/City/Barangay/Street/ZIP are not collected outside the Philippines) and, for the
 * handful of countries that span multiple timezones, shows a Timezone picker instead - other
 * countries resolve a single timezone server-side from Country alone. Used by both the
 * registration form and profile-management "Update Information" form - each page just renders a
 * container matching the markup contract below and calls `new AddressCascade(container, {initial:
 * {...}})`.
 *
 * Expected markup inside a `[data-address-cascade]` container (see register.blade.php for the
 * actual rendering):
 *   [data-address-field="country"]                                  <select>
 *   [data-address-field="region"][data-role="select"|"text"]        one of each, name="region"
 *   [data-address-field="province"][data-role="select"|"text"]      one of each, name="province"
 *   [data-address-field="city"][data-role="select"|"text"]          one of each, name="city"
 *   [data-address-field="barangay"]                                 <select>, name="barangay"
 *   [data-address-field="street"]                                   <input>, name="street"
 *   [data-address-field="zip_code"]                                 <input>, name="zip_code"
 *   [data-address-field="timezone"]                                 <select>, name="timezone"
 *   [data-address-loading="region"|"province"|"city"|"barangay"]    loading indicator per level
 *   [data-address-error="..."]                                      error message slot per level
 *   [data-address-region-group]                                     wrapper hidden for non-PH
 *   [data-address-province-group]                                   wrapper hidden for non-PH / when provinceless
 *   [data-address-city-group]                                       wrapper hidden for non-PH
 *   [data-address-barangay-group]                                   wrapper hidden for non-PH
 *   [data-address-zip-group]                                        wrapper hidden for non-PH
 *   [data-address-street-group]                                     wrapper hidden for non-PH
 *   [data-address-timezone-group]                                   wrapper shown only for multi-timezone countries
 *   [data-address-intl-note]                                        caption shown only for non-PH
 * All group/note attributes are optional - a page that doesn't render them (e.g. an older profile
 * form) just won't get the extra hide/show polish; the underlying fields are still correctly
 * disabled either way.
 */
(function (window, document) {
    'use strict';

    var PSGC_BASE = 'https://psgc.gitlab.io/api/';
    var PHILIPPINES = 'Philippines';
    var COUNTRIES = [
        'Philippines', 'United States', 'Canada', 'United Kingdom', 'Australia',
        'Singapore', 'Malaysia', 'Japan', 'South Korea', 'United Arab Emirates',
        'Saudi Arabia', 'Qatar', 'Hong Kong', 'New Zealand', 'Other'
    ];

    /** Countries whose selection reveals a Timezone picker because a single IANA zone can't be
     *  safely assumed from the country alone - kept in sync with Android's AddressHierarchyController. */
    var MULTI_TZ_COUNTRIES = {
        'United States': [
            { id: 'America/New_York', label: 'New York — Eastern Time' },
            { id: 'America/Chicago', label: 'Chicago — Central Time' },
            { id: 'America/Denver', label: 'Denver — Mountain Time' },
            { id: 'America/Los_Angeles', label: 'Los Angeles — Pacific Time' },
            { id: 'America/Anchorage', label: 'Anchorage — Alaska Time' },
            { id: 'Pacific/Honolulu', label: 'Honolulu — Hawaii Time' }
        ],
        'Canada': [
            { id: 'America/St_Johns', label: 'St. John’s — Newfoundland Time' },
            { id: 'America/Halifax', label: 'Halifax — Atlantic Time' },
            { id: 'America/Toronto', label: 'Toronto — Eastern Time' },
            { id: 'America/Winnipeg', label: 'Winnipeg — Central Time' },
            { id: 'America/Edmonton', label: 'Edmonton — Mountain Time' },
            { id: 'America/Vancouver', label: 'Vancouver — Pacific Time' }
        ],
        'Australia': [
            { id: 'Australia/Sydney', label: 'Sydney — Eastern Time' },
            { id: 'Australia/Brisbane', label: 'Brisbane — Eastern Time (no DST)' },
            { id: 'Australia/Adelaide', label: 'Adelaide — Central Time' },
            { id: 'Australia/Darwin', label: 'Darwin — Central Time (no DST)' },
            { id: 'Australia/Perth', label: 'Perth — Western Time' }
        ]
    };

    /** Region list never changes for the lifetime of the page - shared across every instance. */
    var regionCache = null;

    function qs(root, selector) {
        return root.querySelector(selector);
    }

    function field(root, name, role) {
        var sel = '[data-address-field="' + name + '"]' + (role ? '[data-role="' + role + '"]' : '');
        return qs(root, sel);
    }

    function setGroupVisible(el, visible) {
        if (el) el.hidden = !visible;
    }

    function setLoading(root, level, isLoading) {
        var el = qs(root, '[data-address-loading="' + level + '"]');
        if (el) el.hidden = !isLoading;
    }

    function setError(root, level, message) {
        var el = qs(root, '[data-address-error="' + level + '"]');
        var input = field(root, level, 'select') || field(root, level);
        if (el) el.textContent = message || '';
        if (input) input.setAttribute('aria-invalid', message ? 'true' : 'false');
    }

    function fetchJson(url, onSuccess, onError) {
        fetch(url, { headers: { Accept: 'application/json' } })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(onSuccess)
            .catch(function (err) {
                onError(err);
            });
    }

    /**
     * Submitted/stored value is always item.name (what the server's PsgcHierarchyValidator
     * matches against, and what actually needs to reach the database) - NOT item.code. An
     * earlier version of this widget used item.code as the option value, which meant a real
     * browser form submission sent raw PSGC codes (e.g. "130000000") instead of readable
     * location names, so every genuine Philippines address selection failed server-side
     * validation ("Please select a valid region") no matter how correctly the user filled
     * the dropdowns in. Display text defaults to item.name too, but can be overridden per
     * item via item.displayName (see applyRegions() - PSGC's own `name` field is just an
     * abbreviation for NCR/CAR/BARMM, e.g. "NCR" instead of "National Capital Region").
     */
    function fillSelect(select, items, placeholder) {
        select.innerHTML = '';
        var opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = placeholder;
        select.appendChild(opt0);
        items.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = item.name;
            opt.textContent = item.displayName || item.name;
            opt.dataset.name = item.name;
            select.appendChild(opt);
        });
    }

    function AddressCascade(root, options) {
        this.root = root;
        this.options = options || {};
        this.required = this.options.required !== false;
        this.regions = [];
        this.provinces = [];
        this.cities = [];
        this.barangays = [];
        this.provinceHasNoProvinces = false;
        this.isPhilippines = true;
        this.timezoneOptions = null;

        this.countryEl = field(root, 'country');
        this.regionSelect = field(root, 'region', 'select');
        this.regionText = field(root, 'region', 'text');
        this.provinceSelect = field(root, 'province', 'select');
        this.provinceText = field(root, 'province', 'text');
        this.citySelect = field(root, 'city', 'select');
        this.cityText = field(root, 'city', 'text');
        this.barangaySelect = field(root, 'barangay');
        this.streetEl = field(root, 'street');
        this.zipEl = field(root, 'zip_code');
        this.timezoneSelect = field(root, 'timezone');
        this.regionGroup = qs(root, '[data-address-region-group]');
        this.provinceGroup = qs(root, '[data-address-province-group]');
        this.cityGroup = qs(root, '[data-address-city-group]');
        this.barangayGroup = qs(root, '[data-address-barangay-group]');
        this.zipGroup = qs(root, '[data-address-zip-group]');
        this.streetGroup = qs(root, '[data-address-street-group]');
        this.timezoneGroup = qs(root, '[data-address-timezone-group]');
        this.intlNote = qs(root, '[data-address-intl-note]');

        this.init();
    }

    AddressCascade.prototype.init = function () {
        var self = this;

        fillSelect(this.countryEl, COUNTRIES.map(function (c) { return { name: c }; }), 'Select Country');
        this.countryEl.addEventListener('change', function () {
            self.onCountryChange(self.countryEl.value, null);
        });

        this.regionSelect.addEventListener('change', function () { self.onRegionChange(); });
        this.provinceSelect.addEventListener('change', function () { self.onProvinceChange(); });
        this.citySelect.addEventListener('change', function () { self.onCityChange(); });

        var initial = this.options.initial || {};
        var startCountry = initial.country || PHILIPPINES;
        this.countryEl.value = startCountry;
        if (this.countryEl.value !== startCountry) {
            // Saved country isn't one of the known options (e.g. custom old data) - fall back
            // to "Other" so nothing is silently lost.
            this.countryEl.value = 'Other';
        }

        this.onCountryChange(this.countryEl.value, (initial.region || initial.timezone) ? function () {
            self.populateSavedRegion(initial);
        } : null);
    };

    AddressCascade.prototype.onCountryChange = function (country, afterReady) {
        this.isPhilippines = (country || '').trim().toLowerCase() === PHILIPPINES.toLowerCase();

        this.selectedRegion = null;
        this.selectedProvince = null;
        this.selectedCity = null;
        this.selectedBarangay = null;
        this.provinceHasNoProvinces = false;
        this.regions = [];
        this.provinces = [];
        this.cities = [];
        this.barangays = [];

        [this.regionSelect, this.provinceSelect, this.citySelect, this.barangaySelect].forEach(function (sel) {
            sel.innerHTML = '';
            sel.disabled = true;
        });
        this.regionText.value = '';
        this.provinceText.value = '';
        this.cityText.value = '';
        this.regionText.hidden = true;
        this.regionText.disabled = true;
        this.provinceText.hidden = true;
        this.provinceText.disabled = true;
        this.cityText.hidden = true;
        this.cityText.disabled = true;
        ['region', 'province', 'city', 'barangay', 'timezone'].forEach(function (level) {
            setError(this.root, level, '');
        }, this);

        this.setupTimezone(country);
        setGroupVisible(this.intlNote, !this.isPhilippines);

        if (this.isPhilippines) {
            setGroupVisible(this.regionGroup, true);
            setGroupVisible(this.provinceGroup, true);
            setGroupVisible(this.cityGroup, true);
            setGroupVisible(this.barangayGroup, true);
            setGroupVisible(this.zipGroup, true);
            setGroupVisible(this.streetGroup, true);

            this.regionSelect.hidden = false;
            this.provinceSelect.hidden = false;
            this.citySelect.hidden = false;
            this.barangaySelect.disabled = true;

            if (this.streetEl) this.streetEl.disabled = false;
            if (this.zipEl) {
                this.zipEl.disabled = false;
                this.zipEl.setAttribute('inputmode', 'numeric');
                this.zipEl.setAttribute('maxlength', '4');
            }

            this.loadRegions(afterReady);
        } else {
            // Region/Province/City/Barangay/Street/ZIP are only ever collected for Philippine
            // addresses - hide the whole block (not just disable it) for every other country,
            // and make sure every underlying field is disabled too so a hidden field never
            // actually submits a stray value.
            setGroupVisible(this.regionGroup, false);
            setGroupVisible(this.provinceGroup, false);
            setGroupVisible(this.cityGroup, false);
            setGroupVisible(this.barangayGroup, false);
            setGroupVisible(this.zipGroup, false);
            setGroupVisible(this.streetGroup, false);

            this.regionSelect.hidden = true;
            this.provinceSelect.hidden = true;
            this.citySelect.hidden = true;
            this.barangaySelect.disabled = true;
            this.barangaySelect.value = '';

            if (this.streetEl) { this.streetEl.disabled = true; this.streetEl.value = ''; }
            if (this.zipEl) { this.zipEl.disabled = true; this.zipEl.value = ''; }

            if (afterReady) afterReady();
        }
    };

    /** Shows/populates the Timezone picker for ambiguous countries; every other known country
     *  resolves a fixed IANA zone silently server-side (no client picker needed). */
    AddressCascade.prototype.setupTimezone = function (country) {
        if (!this.timezoneSelect) return;

        this.timezoneSelect.innerHTML = '';
        var options = MULTI_TZ_COUNTRIES[country];
        if (options) {
            this.timezoneOptions = options;
            fillSelect(this.timezoneSelect, options.map(function (o) { return { name: o.id, displayName: o.label }; }), 'Select Timezone');
            this.timezoneSelect.disabled = false;
            setGroupVisible(this.timezoneGroup, true);
        } else {
            this.timezoneOptions = null;
            this.timezoneSelect.disabled = true;
            this.timezoneSelect.value = '';
            setGroupVisible(this.timezoneGroup, false);
        }
    };

    AddressCascade.prototype.loadRegions = function (afterReady) {
        var self = this;
        if (regionCache) {
            this.applyRegions(regionCache);
            this.regionSelect.disabled = false;
            if (afterReady) afterReady();
            return;
        }

        setLoading(this.root, 'region', true);
        fetchJson(PSGC_BASE + 'regions/', function (data) {
            setLoading(self.root, 'region', false);
            regionCache = data;
            self.applyRegions(data);
            self.regionSelect.disabled = false;
            if (afterReady) afterReady();
        }, function () {
            setLoading(self.root, 'region', false);
            setError(self.root, 'region', 'Could not load regions. Check your connection and try again.');
            self.attachRetry('region', function () { self.loadRegions(afterReady); });
        });
    };

    AddressCascade.prototype.applyRegions = function (data) {
        // PSGC's own `name` field is already the descriptive label for most regions (e.g.
        // "Ilocos Region"), but for NCR/CAR/BARMM it's just the short abbreviation while the
        // full descriptive name lives in `regionName` instead - show whichever is more
        // descriptive. The submitted/stored value is still always item.name either way (see
        // fillSelect) - only the dropdown's visible label changes here.
        this.regions = data.map(function (r) {
            var display = (r.regionName && r.regionName.length > r.name.length) ? r.regionName : r.name;
            return { code: r.code, name: r.name, displayName: display };
        });
        fillSelect(this.regionSelect, this.regions, 'Select Region');
    };

    AddressCascade.prototype.onRegionChange = function (afterReady) {
        var name = this.regionSelect.value;
        this.selectedRegion = this.regions.find(function (r) { return r.name === name; }) || null;

        this.provinceHasNoProvinces = false;
        this.provinces = [];
        this.provinceSelect.innerHTML = '';
        this.provinceSelect.disabled = true;
        setGroupVisible(this.provinceGroup, true);
        this.resetCityAndBelow();

        if (!this.selectedRegion) return;

        var self = this;
        var code = this.selectedRegion.code;
        setLoading(this.root, 'province', true);
        setError(this.root, 'province', '');
        fetchJson(PSGC_BASE + 'regions/' + code + '/provinces/', function (data) {
            setLoading(self.root, 'province', false);
            if (!data.length) {
                // Provinceless region (e.g. NCR) - skip straight to that region's cities.
                self.provinceHasNoProvinces = true;
                setGroupVisible(self.provinceGroup, false);
                self.loadCities(PSGC_BASE + 'regions/' + code + '/cities-municipalities/', afterReady);
            } else {
                self.provinces = data;
                fillSelect(self.provinceSelect, data, 'Select Province');
                self.provinceSelect.disabled = false;
                if (afterReady) afterReady();
            }
        }, function () {
            setLoading(self.root, 'province', false);
            setError(self.root, 'province', 'Could not load provinces. Check your connection and try again.');
            self.attachRetry('province', function () { self.onRegionChange(afterReady); });
        });
    };

    AddressCascade.prototype.onProvinceChange = function (afterReady) {
        var name = this.provinceSelect.value;
        this.selectedProvince = this.provinces.find(function (p) { return p.name === name; }) || null;
        this.resetCityAndBelow();
        if (!this.selectedProvince) return;

        this.loadCities(PSGC_BASE + 'provinces/' + this.selectedProvince.code + '/cities-municipalities/', afterReady);
    };

    AddressCascade.prototype.loadCities = function (url, afterReady) {
        var self = this;
        setLoading(this.root, 'city', true);
        setError(this.root, 'city', '');
        fetchJson(url, function (data) {
            setLoading(self.root, 'city', false);
            self.cities = data;
            fillSelect(self.citySelect, data, 'Select City / Municipality');
            self.citySelect.disabled = false;
            if (afterReady) afterReady();
        }, function () {
            setLoading(self.root, 'city', false);
            setError(self.root, 'city', 'Could not load cities/municipalities. Check your connection and try again.');
            self.attachRetry('city', function () { self.loadCities(url, afterReady); });
        });
    };

    AddressCascade.prototype.onCityChange = function (afterReady) {
        var name = this.citySelect.value;
        this.selectedCity = this.cities.find(function (c) { return c.name === name; }) || null;
        this.resetBarangayAndBelow();
        if (!this.selectedCity) return;

        var self = this;
        var code = this.selectedCity.code;
        setLoading(this.root, 'barangay', true);
        setError(this.root, 'barangay', '');
        fetchJson(PSGC_BASE + 'cities-municipalities/' + code + '/barangays/', function (data) {
            setLoading(self.root, 'barangay', false);
            self.barangays = data;
            fillSelect(self.barangaySelect, data, 'Select Barangay');
            self.barangaySelect.disabled = false;
            if (afterReady) afterReady();
        }, function () {
            setLoading(self.root, 'barangay', false);
            setError(self.root, 'barangay', 'Could not load barangays. Check your connection and try again.');
            self.attachRetry('barangay', function () { self.onCityChange(afterReady); });
        });
    };

    AddressCascade.prototype.resetCityAndBelow = function () {
        this.selectedCity = null;
        this.cities = [];
        this.citySelect.innerHTML = '';
        this.citySelect.disabled = true;
        this.resetBarangayAndBelow();
    };

    AddressCascade.prototype.resetBarangayAndBelow = function () {
        this.selectedBarangay = null;
        this.barangays = [];
        this.barangaySelect.innerHTML = '';
        this.barangaySelect.disabled = true;
    };

    /** Small "Retry" link injected next to a failed level's error message. */
    AddressCascade.prototype.attachRetry = function (level, retryFn) {
        var el = qs(this.root, '[data-address-error="' + level + '"]');
        if (!el) return;
        var link = document.createElement('button');
        link.type = 'button';
        link.className = 'btn btn-link btn-sm p-0 ms-1 align-baseline';
        link.textContent = 'Retry';
        link.addEventListener('click', retryFn);
        el.appendChild(document.createTextNode(' '));
        el.appendChild(link);
    };

    /** Re-walks the live hierarchy to resolve a previously-saved address by name (edit mode). */
    AddressCascade.prototype.populateSavedRegion = function (saved) {
        var self = this;
        if (!this.isPhilippines) {
            if (saved.timezone && this.timezoneSelect && !this.timezoneSelect.disabled) {
                this.timezoneSelect.value = saved.timezone;
            }
            return;
        }
        if (!saved.region) return;
        var region = this.regions.find(function (r) { return r.name.toLowerCase() === saved.region.toLowerCase(); });
        if (!region) return;
        this.regionSelect.value = region.name;
        this.onRegionChange(function () { self.populateSavedProvince(saved); });
    };

    AddressCascade.prototype.populateSavedProvince = function (saved) {
        var self = this;
        if (!this.provinceHasNoProvinces) {
            if (!saved.province) return;
            var province = this.provinces.find(function (p) { return p.name.toLowerCase() === saved.province.toLowerCase(); });
            if (!province) return;
            this.provinceSelect.value = province.name;
            this.onProvinceChange(function () { self.populateSavedCity(saved); });
        } else {
            this.populateSavedCity(saved);
        }
    };

    AddressCascade.prototype.populateSavedCity = function (saved) {
        var self = this;
        if (!saved.city) return;
        var city = this.cities.find(function (c) { return c.name.toLowerCase() === saved.city.toLowerCase(); });
        if (!city) return;
        this.citySelect.value = city.name;
        this.onCityChange(function () {
            if (!saved.barangay) return;
            var barangay = self.barangays.find(function (b) { return b.name.toLowerCase() === saved.barangay.toLowerCase(); });
            if (barangay) self.barangaySelect.value = barangay.name;
        });
    };

    /**
     * Enforces the whole chain is filled for Philippine addresses (skipping Province for
     * provinceless regions); non-PH only requires Country (and a Timezone pick, when the picker
     * is shown) - unless this instance was created with `required: false` (registration and
     * mandatory-address profile forms leave this at the default true; a profile form that allows
     * saving other fields without a complete address, like guest.profile.show, sets
     * data-address-required="false" on the container). In optional mode, submission is never
     * blocked here - the backend's own PsgcHierarchyValidator still catches a genuinely
     * malformed combination if one was actually submitted.
     */
    AddressCascade.prototype.validate = function () {
        if (!this.required) return true;

        var ok = true;

        function fail(root, level, input, message) {
            setError(root, level, message);
            if (ok && input) input.focus();
            ok = false;
        }

        if (!this.countryEl.value) fail(this.root, 'country', this.countryEl, 'Please select your country.');

        if (this.isPhilippines) {
            if (!this.regionSelect.value) fail(this.root, 'region', this.regionSelect, 'Please select your region.');
            if (!this.provinceHasNoProvinces && !this.provinceSelect.value) fail(this.root, 'province', this.provinceSelect, 'Please select your province.');
            if (!this.citySelect.value) fail(this.root, 'city', this.citySelect, 'Please select your city or municipality.');
            if (!this.barangaySelect.value) fail(this.root, 'barangay', this.barangaySelect, 'Please select your barangay.');
            if (!this.streetEl.value.trim()) fail(this.root, 'street', this.streetEl, 'Street / house number is required.');
        } else if (this.timezoneOptions && this.timezoneSelect && !this.timezoneSelect.value) {
            fail(this.root, 'timezone', this.timezoneSelect, 'Please select your timezone.');
        }

        return ok;
    };

    window.AddressCascade = AddressCascade;

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-address-cascade]').forEach(function (root) {
            var initial = {};
            try {
                initial = JSON.parse(root.getAttribute('data-initial') || '{}');
            } catch (e) { /* malformed data-initial - start empty rather than throw */ }

            var required = root.getAttribute('data-address-required') !== 'false';
            var instance = new AddressCascade(root, { initial: initial, required: required });
            root.__addressCascade = instance;

            var form = root.closest('form');
            if (form) {
                form.addEventListener('submit', function (event) {
                    if (!instance.validate()) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                });
            }
        });
    });
})(window, document);
