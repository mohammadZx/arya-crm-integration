/**
 * جمع‌کنندهٔ خطاهای مرورگر — افزونهٔ arya-portal-integration.
 *
 * هدف: خطایی که فقط در مرورگر کاربر رخ می‌دهد هم همان‌جایی برود که خطاهای
 * سمت سرور می‌روند (فایل روزانهٔ افزونه، و از آنجا CRM).
 *
 * سه ورودی دارد:
 *   window.onerror                → JS_ERROR
 *   unhandledrejection            → JS_PROMISE_REJECTION
 *   AryaPortalLogger.reportRequest → JS_REQUEST_FAILED (صریح، از کد خودمان)
 *
 * سقف و حذف تکراری‌ها سمت کلاینت هم هست: یک خطای در حال حلقه‌زدن نباید
 * صدها درخواست به admin-ajax بزند.
 */
(function (window, document) {
    'use strict';

    var cfg = window.aryaPortalLogger || {};
    if (!cfg.ajaxurl || !cfg.action || cfg.enabled === false) {
        return;
    }

    var MAX_PER_PAGE = 10;
    var sent = 0;
    var seen = {};

    function fingerprint(code, message, context) {
        return [code, message, context.source || '', context.lineno || ''].join('|');
    }

    function send(code, message, context) {
        if (sent >= MAX_PER_PAGE) {
            return;
        }

        context = context || {};
        var key = fingerprint(code, message, context);
        if (seen[key]) {
            return;
        }
        seen[key] = true;
        sent++;

        var body = new URLSearchParams();
        body.append('action', cfg.action);
        body.append('nonce', cfg.nonce || '');
        body.append('code', code);
        body.append('message', String(message || '').slice(0, 1000));
        body.append('page', window.location.href);

        try {
            body.append('context', JSON.stringify(context));
        } catch (e) {
            body.append('context', '{}');
        }

        // keepalive تا گزارش خطایی که همراه ترک صفحه رخ داده هم برسد.
        try {
            fetch(cfg.ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).catch(function () { /* گزارشِ ناموفقِ گزارش، بی‌صدا */ });
        } catch (e) { /* هیچ */ }
    }

    window.addEventListener('error', function (event) {
        if (!event) {
            return;
        }

        // خطای بارگذاری منبع (img/script/link) پیام ندارد؛ src را گزارش می‌کنیم.
        if (event.target && event.target !== window && event.target.tagName) {
            send('JS_ERROR', 'بارگذاری منبع ناموفق: ' + event.target.tagName, {
                source: event.target.src || event.target.href || '',
                component: 'resource'
            });
            return;
        }

        send('JS_ERROR', event.message, {
            source: event.filename || '',
            lineno: event.lineno || 0,
            colno: event.colno || 0,
            stack: event.error && event.error.stack ? String(event.error.stack).slice(0, 2000) : ''
        });
    }, true);

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event ? event.reason : null;
        var message = 'Promise رد شد';
        var stack = '';

        if (reason instanceof Error) {
            message = reason.message;
            stack = String(reason.stack || '').slice(0, 2000);
        } else if (reason) {
            try {
                message = typeof reason === 'string' ? reason : JSON.stringify(reason).slice(0, 500);
            } catch (e) {
                message = String(reason);
            }
        }

        send('JS_PROMISE_REJECTION', message, { stack: stack });
    });

    /** API عمومی برای کدهای خودمان (هشدارهای داشبورد، فرم اطلاعات و …). */
    window.AryaPortalLogger = {
        log: function (code, message, context) {
            send(code || 'JS_ERROR', message, context || {});
        },
        reportRequest: function (message, context) {
            send('JS_REQUEST_FAILED', message, context || {});
        }
    };
})(window, document);
