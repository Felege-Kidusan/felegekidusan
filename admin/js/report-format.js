(function (root) {
    'use strict';

    function asValidDate(value) {
        var date = value instanceof Date ? value : new Date(value === undefined ? Date.now() : value);
        return Number.isNaN(date.getTime()) ? new Date() : date;
    }

    function calendarValue(method, date, style) {
        try {
            var calendar = root.WBWSCalendar;
            if (calendar && typeof calendar[method] === 'function') {
                var value = calendar[method](date, style);
                if (typeof value === 'string' && value.trim() !== '') {
                    return value;
                }
            }
        } catch (error) {
            // A reporting timestamp must still work if the optional calendar
            // bundle is missing, outdated, or fails during initialization.
        }
        return '';
    }

    function timestamp(value) {
        var date = asValidDate(value);
        var formatted = calendarValue('formatDateTime', date);
        if (formatted !== '') {
            return formatted;
        }
        try {
            return date.toLocaleString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            });
        } catch (error) {
            return date.toISOString().replace('T', ' ').replace(/\.\d{3}Z$/, ' UTC');
        }
    }

    function longDate(value) {
        var date = asValidDate(value);
        var formatted = calendarValue('formatDate', date, 'long');
        if (formatted !== '') {
            return formatted;
        }
        try {
            return date.toLocaleDateString('en-GB', {
                day: '2-digit',
                month: 'long',
                year: 'numeric'
            });
        } catch (error) {
            return date.toISOString().slice(0, 10);
        }
    }

    var api = Object.freeze({
        timestamp: timestamp,
        longDate: longDate
    });
    root.WBWSReportFormat = api;
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
}(typeof window !== 'undefined' ? window : globalThis));
