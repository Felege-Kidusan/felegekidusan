(function (global) {
    'use strict';

    function create() {
        if (global.crypto && typeof global.crypto.randomUUID === 'function') {
            return global.crypto.randomUUID();
        }
        if (global.crypto && typeof global.crypto.getRandomValues === 'function') {
            var bytes = new Uint8Array(16);
            global.crypto.getRandomValues(bytes);
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
            var hex = Array.prototype.map.call(bytes, function (byte) {
                return byte.toString(16).padStart(2, '0');
            }).join('');
            return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16)
                + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
        }
        // Compatibility fallback for browsers without Web Crypto. The value is
        // a collision-resistant request correlation key, never an authenticator.
        return 'reg-' + Date.now().toString(36) + '-'
            + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
    }

    function ensure(form, fieldName) {
        var name = fieldName || 'registration_request_id';
        var field = form && form.querySelector('input[name="' + name + '"]');
        if (!field) return '';
        if (!field.value) field.value = create();
        return field.value;
    }

    global.SsmsRequestId = Object.freeze({ create: create, ensure: ensure });
}(window));
