/**
 * Contact form.
 *
 * Progressive enhancement: the form is a plain POST to php/contact.php and
 * works on its own. When JavaScript is available we submit in the
 * background instead, so the visitor keeps their place on the page and sees
 * the result inline.
 */
(function () {
    'use strict';

    var form = document.getElementById('contact-form');
    if (!form) {
        return;
    }

    var status = document.getElementById('contact-status');
    var submit = document.getElementById('contact-submit');
    var renderedAt = document.getElementById('contact-rendered-at');
    var submitLabel = submit ? submit.textContent : 'Send Message';

    // Stamp when the page was rendered, so the backend can reject
    // submissions that arrive implausibly fast.
    if (renderedAt) {
        renderedAt.value = String(Math.floor(Date.now() / 1000));
    }

    function showStatus(message, isSuccess) {
        if (!status) {
            return;
        }
        status.textContent = message;
        status.className = 'form-status is-visible ' + (isSuccess ? 'is-success' : 'is-error');
    }

    function clearStatus() {
        if (status) {
            status.textContent = '';
            status.className = 'form-status';
        }
    }

    function setBusy(busy) {
        if (!submit) {
            return;
        }
        submit.disabled = busy;
        submit.textContent = busy ? 'Sending...' : submitLabel;
    }

    // Clear the error outline as soon as the visitor starts fixing a field.
    form.addEventListener('input', function (event) {
        if (event.target.classList) {
            event.target.classList.remove('has-error');
        }
    });

    /**
     * Mirror the server's rules so obvious mistakes are caught without a
     * round trip. The server validates everything again regardless.
     */
    function firstInvalidField() {
        var name = form.elements.name;
        var email = form.elements.email;
        var message = form.elements.message;

        if (!name.value.trim()) {
            return { field: name, message: 'Please tell us your name.' };
        }
        if (!email.value.trim()) {
            return { field: email, message: 'Please add an email address so we can reply.' };
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
            return { field: email, message: 'That email address does not look right.' };
        }
        if (!message.value.trim()) {
            return { field: message, message: 'Please include a message.' };
        }
        return null;
    }

    form.addEventListener('submit', function (event) {
        // Without fetch, fall through to a normal form post.
        if (typeof window.fetch !== 'function') {
            return;
        }

        event.preventDefault();
        clearStatus();

        var invalid = firstInvalidField();
        if (invalid) {
            invalid.field.classList.add('has-error');
            invalid.field.focus();
            showStatus(invalid.message, false);
            return;
        }

        setBusy(true);

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                var succeeded = result.ok && result.data && result.data.ok;
                var message = (result.data && result.data.message)
                    || 'Something went wrong. Please try again.';

                showStatus(message, succeeded);

                if (succeeded) {
                    form.reset();
                    if (renderedAt) {
                        renderedAt.value = String(Math.floor(Date.now() / 1000));
                    }
                }
            })
            .catch(function () {
                showStatus(
                    'We could not reach the server. Please check your connection or email us directly.',
                    false
                );
            })
            .then(function () {
                setBusy(false);
            });
    });
})();
