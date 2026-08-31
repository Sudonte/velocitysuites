/**
 * Client-side presentation layer for the 5-step registration wizard
 * (register.blade.php). Purely a step-visibility/validation-gating layer
 * over the existing single <form> - every field keeps its original name/id,
 * and the form still submits everything in one POST to register.post exactly
 * as before this wizard existed, so RegisterController's validation, OTP
 * flow, and transaction logic all keep working completely unchanged.
 *
 * Depends on window.AddressCascade (address-cascade.js) already having
 * initialized the [data-address-cascade] container and exposed its instance
 * as root.__addressCascade before step 2's "Continue" is ever clicked (both
 * scripts run their own DOMContentLoaded handler; address-cascade.js is
 * loaded first, so its handler registers - and its synchronous constructor
 * work runs - before this one).
 */
(function (window, document) {
    'use strict';

    var TOTAL_STEPS = 5;
    var STEP_LABELS = {
        1: 'Personal Information',
        2: 'Contact & Address',
        3: 'Account Security',
        4: 'Profile',
        5: 'Review & Create Account'
    };

    var form = document.getElementById('registerForm');
    if (!form) return;

    var steps = Array.prototype.slice.call(form.querySelectorAll('.wizard-step'));
    var stepperDesktopItems = Array.prototype.slice.call(document.querySelectorAll('#wizardStepperDesktop .wizard-stepper-item'));
    var mobileLabel = document.getElementById('wizardStepperMobileLabel');
    var mobileFill = document.getElementById('wizardStepperMobileFill');

    var currentStep = 1;
    var highestCompleted = 0;

    function stepEl(n) {
        return form.querySelector('.wizard-step[data-step="' + n + '"]');
    }

    function showStep(n) {
        currentStep = n;
        steps.forEach(function (el) {
            el.classList.toggle('active', Number(el.getAttribute('data-step')) === n);
        });

        stepperDesktopItems.forEach(function (item) {
            var s = Number(item.getAttribute('data-step'));
            var circle = item.querySelector('.wizard-stepper-circle');
            item.classList.remove('active', 'completed');
            if (s === n) {
                item.classList.add('active');
                if (circle) circle.textContent = s;
            } else if (s <= highestCompleted) {
                // Only ever reached via the [data-wizard-next] handler below,
                // which sets highestCompleted AFTER validateStep() passes -
                // never just for having been visited (see AQ in the spec).
                item.classList.add('completed');
                if (circle) circle.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i>';
            } else if (circle) {
                circle.textContent = s;
            }
        });

        if (mobileLabel) mobileLabel.textContent = 'Step ' + n + ' of ' + TOTAL_STEPS + ' — ' + STEP_LABELS[n];
        if (mobileFill) mobileFill.style.width = Math.round((n / TOTAL_STEPS) * 100) + '%';

        if (n === 5) populateReview();

        // New step title should be visible without extra scrolling, especially on mobile.
        var card = form.closest('.auth-card');
        if (card && typeof card.scrollIntoView === 'function') {
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    /**
     * Validates only the fields inside one step, independent of the rest of
     * the form - each control's own reportValidity() is scoped to that
     * control alone (part of the Constraint Validation API), so this never
     * blocks "Continue" on step 1 because step 3's password is still empty.
     * Also mirrors each control's validity into aria-invalid so screen
     * readers get the same signal sighted users get from the red border.
     */
    function validateStep(n) {
        var el = stepEl(n);
        if (!el) return true;

        var controls = Array.prototype.slice.call(el.querySelectorAll('input, select, textarea'))
            .filter(function (c) { return !c.disabled; });

        var allValid = true;
        controls.forEach(function (c) {
            var valid = c.checkValidity();
            c.setAttribute('aria-invalid', valid ? 'false' : 'true');
            if (!valid) allValid = false;
        });

        if (!allValid) {
            for (var i = 0; i < controls.length; i++) {
                if (!controls[i].reportValidity()) break;
            }
            return false;
        }

        if (n === 2) {
            var addressRoot = el.querySelector('[data-address-cascade]');
            if (addressRoot && addressRoot.__addressCascade && !addressRoot.__addressCascade.validate()) {
                return false;
            }
        }

        return true;
    }

    /** Every step, in order - used by the pre-submit sweep so an edit that reintroduces an invalid value can't slip through via Step 5. */
    function firstInvalidStep() {
        for (var n = 1; n <= TOTAL_STEPS; n++) {
            if (!validateStep(n)) return n;
        }
        return 0;
    }

    document.querySelectorAll('[data-wizard-next]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!validateStep(currentStep)) return;
            highestCompleted = Math.max(highestCompleted, currentStep);
            if (currentStep < TOTAL_STEPS) showStep(currentStep + 1);
        });
    });

    // Final safety net at actual submission time: the form has novalidate
    // (each step manages its own scoped validation instead), so nothing else
    // stops a genuinely invalid value from reaching the server if it got
    // into that state some other way (e.g. edited via a Step 5 "Edit" link,
    // then Back-navigated past without hitting Continue again). Registered
    // before the inline disable/spinner script further down this file (script
    // tags run in source order), so this listener's preventDefault() below
    // stops the submission before that one ever sees it.
    form.addEventListener('submit', function (event) {
        var invalidStep = firstInvalidStep();
        if (invalidStep) {
            event.preventDefault();
            event.stopImmediatePropagation();
            showStep(invalidStep);
        }
    });

    document.querySelectorAll('[data-wizard-back]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (currentStep > 1) showStep(currentStep - 1);
        });
    });

    document.querySelectorAll('[data-wizard-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = Number(btn.getAttribute('data-wizard-edit'));
            if (target >= 1 && target <= TOTAL_STEPS) showStep(target);
        });
    });

    // --- Date of Birth -> read-only Age ---
    var dobInput = document.getElementById('date_of_birth');
    var ageInput = document.getElementById('age');

    function calculateAge(dobValue) {
        if (!dobValue) return null;
        var dob = new Date(dobValue + 'T00:00:00');
        if (isNaN(dob.getTime())) return null;
        var today = new Date();
        var age = today.getFullYear() - dob.getFullYear();
        var monthDiff = today.getMonth() - dob.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) age--;
        return age >= 0 ? age : null;
    }

    function updateAgeFromDob() {
        if (!dobInput || !ageInput) return;
        var age = calculateAge(dobInput.value);
        ageInput.value = age === null ? '' : age;
        if (age !== null && age < 18) {
            ageInput.setCustomValidity('You must be at least 18 years old to create an account.');
        } else {
            ageInput.setCustomValidity('');
        }
        ageInput.setAttribute('aria-invalid', ageInput.checkValidity() ? 'false' : 'true');
    }

    if (dobInput) {
        dobInput.addEventListener('input', updateAgeFromDob);
        dobInput.addEventListener('change', updateAgeFromDob);
        updateAgeFromDob(); // covers a value already present via old() after a failed submit
    }

    // --- Mobile format hint, kept in sync with the selected Country - instant feedback
    //     only, the real check happens server-side (PhoneValidationService/ValidPhoneNumber)
    //     on submit. Also flags the one common, unambiguous mistake client-side: a
    //     Philippine-shaped number left behind after switching to another country. ---
    var countrySelect = document.getElementById('addrCountry');
    var mobileInput = document.getElementById('mobile_number');
    var mobileHint = document.getElementById('mobileFormatHint');
    var PH_SHAPE = /^(09|\+639|639)\d{9}$/;

    var PHONE_FORMAT_HINTS = {
        'Philippines': '09XXXXXXXXX or +639XXXXXXXXX.',
        'United States': 'e.g. +12025550123',
        'Canada': 'e.g. +12025550123',
        'United Kingdom': 'e.g. +447911123456',
        'Australia': 'e.g. +61412345678',
        'Singapore': 'e.g. +6591234567',
        'Malaysia': 'e.g. +60123456789',
        'Japan': 'e.g. +819012345678',
        'South Korea': 'e.g. +821012345678',
        'United Arab Emirates': 'e.g. +971501234567',
        'Saudi Arabia': 'e.g. +966512345678',
        'Qatar': 'e.g. +97433123456',
        'Hong Kong': 'e.g. +85251234567',
        'New Zealand': 'e.g. +64211234567'
    };

    function updateMobileHint() {
        if (!countrySelect || !mobileHint) return;
        var country = countrySelect.value;
        var value = mobileInput ? mobileInput.value.trim() : '';
        if (country && country !== 'Philippines' && PH_SHAPE.test(value)) {
            mobileHint.textContent = 'This looks like a Philippine number - please update it for ' + country + '.';
            mobileHint.classList.add('text-danger');
        } else {
            mobileHint.textContent = PHONE_FORMAT_HINTS[country] || 'Enter a valid mobile number with country code.';
            mobileHint.classList.remove('text-danger');
        }
    }

    if (countrySelect) {
        updateMobileHint();
        countrySelect.addEventListener('change', updateMobileHint);
        if (mobileInput) mobileInput.addEventListener('input', updateMobileHint);
    }

    // --- Password requirement checklist + strength meter ---
    var passwordInput = document.getElementById('password');
    var confirmInput = document.getElementById('password_confirmation');
    var checklist = document.getElementById('passwordChecklist');
    var strengthFill = document.getElementById('passwordStrengthFill');
    var strengthText = document.getElementById('passwordStrengthText');
    var matchHint = document.getElementById('passwordMatchHint');

    var STRENGTH_LEVELS = [
        { label: '', color: '#e2e2e2', width: 0 },
        { label: 'Weak', color: '#dc3545', width: 25 },
        { label: 'Fair', color: '#fd7e14', width: 50 },
        { label: 'Good', color: '#ffc107', width: 75 },
        { label: 'Strong', color: '#1f8a3b', width: 100 }
    ];

    function updatePasswordChecklist() {
        if (!passwordInput || !checklist) return;
        var value = passwordInput.value;
        var checks = {
            length: value.length >= 8,
            upper: /[A-Z]/.test(value),
            lower: /[a-z]/.test(value),
            number: /[0-9]/.test(value)
        };
        Object.keys(checks).forEach(function (key) {
            var li = checklist.querySelector('[data-req="' + key + '"]');
            if (li) li.classList.toggle('met', checks[key]);
        });

        // Only the 8-character minimum is an actual requirement (matches
        // RegisterController's Password::min(8) rule) - the rest are
        // informational strength signals only, so "Continue" is never
        // blocked on a rule the backend doesn't actually enforce.
        var metCount = Object.keys(checks).filter(function (k) { return checks[k]; }).length;
        var level = value.length === 0 ? 0 : Math.max(1, metCount);
        var info = STRENGTH_LEVELS[Math.min(level, 4)];
        if (strengthFill) {
            strengthFill.style.width = info.width + '%';
            strengthFill.style.backgroundColor = info.color;
        }
        if (strengthText) strengthText.textContent = value.length === 0 ? '' : ('Password strength: ' + info.label);
    }

    function updatePasswordMatch() {
        if (!passwordInput || !confirmInput) return;
        if (confirmInput.value && confirmInput.value !== passwordInput.value) {
            confirmInput.setCustomValidity('Passwords do not match.');
            if (matchHint) { matchHint.textContent = 'Passwords do not match.'; matchHint.style.color = '#dc3545'; }
        } else {
            confirmInput.setCustomValidity('');
            if (matchHint) { matchHint.textContent = 'Must match the password above.'; matchHint.style.color = ''; }
        }
        confirmInput.setAttribute('aria-invalid', confirmInput.checkValidity() ? 'false' : 'true');
    }

    if (passwordInput) {
        passwordInput.addEventListener('input', function () {
            updatePasswordChecklist();
            updatePasswordMatch();
        });
    }
    if (confirmInput) {
        confirmInput.addEventListener('input', updatePasswordMatch);
    }

    // --- Profile picture: styled "Choose Image" trigger + Replace/Remove ---
    // Layers on top of app.js's existing delegated #profilePictureInput
    // change handler (which renders the FileReader preview) rather than
    // replacing it - that handler keeps working unchanged; this just adds
    // the filename display and the idle/selected button-group toggle.
    var fileInput = document.getElementById('profilePictureInput');
    var btnChooseImage = document.getElementById('btnChooseImage');
    var btnReplaceImage = document.getElementById('btnReplaceImage');
    var btnRemoveImage = document.getElementById('btnRemoveImage');
    var pictureActionsIdle = document.getElementById('profilePictureActions');
    var pictureActionsSelected = document.getElementById('profilePictureSelectedActions');
    var pictureFileName = document.getElementById('profilePictureFileName');
    var picturePreviewImg = document.getElementById('profilePicturePreview');
    var picturePlaceholder = document.getElementById('profilePicturePlaceholder');

    function openFilePicker() {
        if (fileInput) fileInput.click();
    }
    if (btnChooseImage) btnChooseImage.addEventListener('click', openFilePicker);
    if (btnReplaceImage) btnReplaceImage.addEventListener('click', openFilePicker);

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            if (!file) return;
            if (pictureFileName) pictureFileName.textContent = file.name;
            if (pictureActionsIdle) pictureActionsIdle.classList.add('d-none');
            if (pictureActionsSelected) pictureActionsSelected.classList.remove('d-none');
        });
    }

    if (btnRemoveImage) {
        btnRemoveImage.addEventListener('click', function () {
            if (fileInput) fileInput.value = '';
            if (picturePreviewImg) { picturePreviewImg.style.display = 'none'; picturePreviewImg.src = ''; }
            if (picturePlaceholder) picturePlaceholder.style.display = '';
            if (pictureActionsSelected) pictureActionsSelected.classList.add('d-none');
            if (pictureActionsIdle) pictureActionsIdle.classList.remove('d-none');
        });
    }

    // --- Review step population ---
    function addressFieldValue(name) {
        var el = document.querySelector('[data-address-field="' + name + '"]:not(:disabled)');
        return el ? el.value.trim() : '';
    }

    function textOf(id) {
        var el = document.getElementById(id);
        return el ? el.value.trim() : '';
    }

    function setReview(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value || '—';
    }

    function populateReview() {
        var first = textOf('first_name');
        var middle = textOf('middle_name');
        var last = textOf('last_name');
        setReview('reviewFullName', [first, middle, last].filter(Boolean).join(' '));

        var dobValue = textOf('date_of_birth');
        if (dobValue) {
            var dobDate = new Date(dobValue + 'T00:00:00');
            setReview('reviewDob', isNaN(dobDate.getTime()) ? dobValue : dobDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }));
        } else {
            setReview('reviewDob', '');
        }
        setReview('reviewAge', textOf('age'));

        var genderSelect = document.getElementById('gender');
        setReview('reviewGender', genderSelect && genderSelect.selectedOptions.length ? genderSelect.selectedOptions[0].textContent : '');
        setReview('reviewMobile', textOf('mobile_number'));

        setReview('reviewEmail', textOf('email'));

        setReview('reviewCountry', addressFieldValue('country'));
        setReview('reviewRegion', addressFieldValue('region'));
        setReview('reviewProvince', addressFieldValue('province'));
        setReview('reviewCity', addressFieldValue('city'));
        setReview('reviewBarangay', addressFieldValue('barangay'));
        setReview('reviewZip', addressFieldValue('zip_code'));
        setReview('reviewStreet', addressFieldValue('street'));
        setReview('reviewTimezone', addressFieldValue('timezone'));

        var picturePreview = document.getElementById('profilePicturePreview');
        var pictureReview = document.getElementById('reviewProfilePicture');
        if (pictureReview) {
            pictureReview.textContent = (picturePreview && picturePreview.style.display !== 'none')
                ? 'Photo selected' : 'No profile picture selected';
        }
    }

    // --- Initial state: land on the first step that actually has a
    //     server-side validation error (a failed submit re-renders with
    //     old() values and @error() classes across whichever fields failed,
    //     which may not be step 1) rather than always resetting to step 1. ---
    function initialStep() {
        var firstInvalid = form.querySelector('.is-invalid');
        if (!firstInvalid) return 1;
        var stepContainer = firstInvalid.closest('.wizard-step');
        return stepContainer ? Number(stepContainer.getAttribute('data-step')) : 1;
    }

    var startStep = initialStep();
    highestCompleted = Math.max(0, startStep - 1);
    showStep(startStep);
})(window, document);
