/**
 * auth.js — login, forgot-password, reset-password form handlers.
 */

'use strict';

$(function () {

    const AUTH_API = '/oms-zoho-dashboard/api/auth.php';

    function postJson(action, payload) {
        return $.ajax({
            url: AUTH_API + '?action=' + encodeURIComponent(action),
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
        });
    }

    function showError($el, message) {
        $el.text(message).prop('hidden', false);
    }

    function errorMessage(jqXHR, fallback) {
        try {
            return JSON.parse(jqXHR.responseText).error || fallback;
        } catch (e) {
            return fallback;
        }
    }

    // ----- Login -----
    const $login = $('#login-form');
    if ($login.length) {
        $login.on('submit', function (e) {
            e.preventDefault();
            const $error  = $('#auth-error').prop('hidden', true);
            const $submit = $login.find('.auth-submit').prop('disabled', true);

            postJson('login', {
                email:      $('#email').val(),
                password:   $('#password').val(),
                csrf_token: $login.find('[name="csrf_token"]').val(),
            })
            .done(function () {
                window.location.href = $login.find('[name="return_to"]').val() || '/oms-zoho-dashboard/index.php';
            })
            .fail(function (jqXHR) {
                showError($error, errorMessage(jqXHR, 'Sign in failed. Please try again.'));
                $submit.prop('disabled', false);
            });
        });
    }

    // ----- Forgot password -----
    const $forgot = $('#forgot-form');
    if ($forgot.length) {
        $forgot.on('submit', function (e) {
            e.preventDefault();
            const $error   = $('#auth-error').prop('hidden', true);
            const $success = $('#auth-success').prop('hidden', true);
            const $submit  = $forgot.find('.auth-submit').prop('disabled', true);

            postJson('forgot_password', {
                email:      $('#email').val(),
                csrf_token: $forgot.find('[name="csrf_token"]').val(),
            })
            .done(function (res) {
                $forgot.find('input').prop('disabled', true);
                $success.text(res.message || 'If that email is registered, a reset link has been sent.').prop('hidden', false);
            })
            .fail(function (jqXHR) {
                showError($error, errorMessage(jqXHR, 'Something went wrong. Please try again.'));
                $submit.prop('disabled', false);
            });
        });
    }

    // ----- Reset / set password -----
    const $reset = $('#reset-form');
    if ($reset.length) {
        $reset.on('submit', function (e) {
            e.preventDefault();
            const $error   = $('#auth-error').prop('hidden', true);
            const $success = $('#auth-success').prop('hidden', true);
            const $submit  = $reset.find('.auth-submit').prop('disabled', true);

            const password = $('#password').val();
            const confirm  = $('#password_confirm').val();
            if (password !== confirm) {
                showError($error, 'Passwords do not match.');
                $submit.prop('disabled', false);
                return;
            }

            postJson('reset_password', {
                token:      $reset.find('[name="token"]').val(),
                password:   password,
                csrf_token: $reset.find('[name="csrf_token"]').val(),
            })
            .done(function () {
                $reset.find('input, button').prop('disabled', true);
                $success.text('Password set. Redirecting to sign in…').prop('hidden', false);
                setTimeout(function () {
                    window.location.href = '/oms-zoho-dashboard/account/login.php';
                }, 1500);
            })
            .fail(function (jqXHR) {
                showError($error, errorMessage(jqXHR, 'Could not reset password. The link may have expired.'));
                $submit.prop('disabled', false);
            });
        });
    }

});
