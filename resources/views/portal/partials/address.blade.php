                <div data-address-cascade data-initial='{"country":"Philippines"}'>
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="addrCountry">Country *</label>
                                <select class="form-control"
                                        id="addrCountry" name="country" data-address-field="country" required></select>
                                <div class="invalid-feedback d-block" id="addrCountry-error" data-address-error="country" role="alert"></div>
                            </div>
                        </div>
                        <div class="col-sm-6" data-address-region-group>
                            <div class="form-group">
                                <label for="addrRegion">Region *</label>
                                <select class="form-control"
                                        id="addrRegion" name="region" data-address-field="region" data-role="select" disabled></select>
                                <input type="text" class="form-control d-none" id="addrRegionText" name="region"
                                       data-address-field="region" data-role="text" placeholder="Region" disabled>
                                <small class="field-caption" data-address-loading="region" hidden><i class="fas fa-spinner fa-spin"></i> Loading regions&hellip;</small>
                                <div class="invalid-feedback d-block" id="addrRegion-error" data-address-error="region" role="alert"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Shown only for non-Philippine countries: no further address detail is
                         collected outside the Philippines. -->
                    <p class="field-caption" data-address-intl-note hidden>Address details are only collected for Philippine addresses.</p>

                    <!-- Timezone: only shown for countries spanning multiple timezones (United
                         States, Canada, Australia); single-timezone countries are resolved
                         server-side from Country alone. -->
                    <div class="row" data-address-timezone-group hidden>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="addrTimezone">Timezone *</label>
                                <select class="form-control"
                                        id="addrTimezone" name="timezone" data-address-field="timezone"></select>
                                <div class="invalid-feedback d-block" id="addrTimezone-error" data-address-error="timezone" role="alert"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-sm-6" data-address-province-group>
                            <div class="form-group">
                                <label for="addrProvince">Province *</label>
                                <select class="form-control"
                                        id="addrProvince" name="province" data-address-field="province" data-role="select" disabled></select>
                                <input type="text" class="form-control d-none" id="addrProvinceText" name="province"
                                       data-address-field="province" data-role="text" placeholder="Province" disabled>
                                <small class="field-caption" data-address-loading="province" hidden><i class="fas fa-spinner fa-spin"></i> Loading provinces&hellip;</small>
                                <div class="invalid-feedback d-block" id="addrProvince-error" data-address-error="province" role="alert"></div>
                            </div>
                        </div>
                        <div class="col-sm-6" data-address-city-group>
                            <div class="form-group">
                                <label for="addrCity">City / Municipality *</label>
                                <select class="form-control"
                                        id="addrCity" name="city" data-address-field="city" data-role="select" disabled></select>
                                <input type="text" class="form-control d-none" id="addrCityText" name="city"
                                       data-address-field="city" data-role="text" placeholder="City / Municipality" disabled>
                                <small class="field-caption" data-address-loading="city" hidden><i class="fas fa-spinner fa-spin"></i> Loading cities&hellip;</small>
                                <div class="invalid-feedback d-block" id="addrCity-error" data-address-error="city" role="alert"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-sm-6" data-address-barangay-group>
                            <div class="form-group">
                                <label for="addrBarangay">Barangay *</label>
                                <select class="form-control"
                                        id="addrBarangay" name="barangay" data-address-field="barangay" disabled></select>
                                <small class="field-caption" data-address-loading="barangay" hidden><i class="fas fa-spinner fa-spin"></i> Loading barangays&hellip;</small>
                                <div class="invalid-feedback d-block" id="addrBarangay-error" data-address-error="barangay" role="alert"></div>
                            </div>
                        </div>
                        <div class="col-sm-6" data-address-zip-group>
                            <div class="form-group">
                                <label for="addrZip">ZIP Code</label>
                                <input type="text" class="form-control"
                                       id="addrZip" name="zip_code" data-address-field="zip_code" value="">
                            </div>
                        </div>
                    </div>

                    <div data-address-street-group>
                        <div class="form-group mb-0">
                            <label for="addrStreet">Street / House No. / Subdivision *</label>
                            <input type="text" class="form-control"
                                   id="addrStreet" name="street" data-address-field="street" value="" required>
                            <div class="invalid-feedback d-block" id="addrStreet-error" data-address-error="street" role="alert"></div>
                        </div>
                    </div>
                </div>
