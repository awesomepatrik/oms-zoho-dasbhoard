/**
 * user-management.js — admin CRUD screen for user accounts.
 */

'use strict';

$(function () {

    const USERS_API   = '/oms-zoho-dashboard/api/users.php';
    const csrfToken    = $('#csrf-token').val();
    const currentUserId = parseInt($('#current-user-id').val(), 10);

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    function apiGet(action) {
        return $.getJSON(USERS_API + '?action=' + encodeURIComponent(action));
    }

    function apiPost(action, payload) {
        return $.ajax({
            url: USERS_API + '?action=' + encodeURIComponent(action),
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(Object.assign({ csrf_token: csrfToken }, payload)),
        });
    }

    function errorMessage(jqXHR, fallback) {
        try {
            return JSON.parse(jqXHR.responseText).error || fallback;
        } catch (e) {
            return fallback;
        }
    }

    function loadUsers() {
        const $wrap = $('#um-table-wrap');
        apiGet('list')
            .done(function (res) {
                const users = res.data || [];
                if (!users.length) {
                    $wrap.html('<p class="detail-empty-msg">No users yet.</p>');
                    return;
                }
                const rows = users.map(function (u) {
                    const badgeCls = u.is_active == 1 ? 'badge-active' : 'badge-stopped';
                    const badgeTxt = u.is_active == 1 ? 'Active' : 'Inactive';
                    return `<tr data-id="${u.id}">
                        <td>${escHtml(u.name)}</td>
                        <td>${escHtml(u.email)}</td>
                        <td>${escHtml(u.role)}</td>
                        <td><span class="badge ${badgeCls}">${badgeTxt}</span></td>
                        <td><button class="btn-msr-action um-edit-btn" type="button" data-id="${u.id}">Edit</button></td>
                    </tr>`;
                }).join('');
                $wrap.html(`
                    <table class="data-table">
                        <thead><tr>
                            <th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th>
                        </tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                `);
                window._umUsers = users;
            })
            .fail(function (jqXHR) {
                $wrap.html('<p class="detail-empty-msg error-msg">' + escHtml(errorMessage(jqXHR, 'Failed to load users.')) + '</p>');
            });
    }

    function openModal(user) {
        $('#um-form-error').prop('hidden', true);
        $('#um-form')[0].reset();
        if (user) {
            $('#um-modal-title').text('Edit user');
            $('#um-form-id').val(user.id);
            $('#um-form-name').val(user.name);
            $('#um-form-email').val(user.email);
            $('#um-form-role').val(user.role);
            $('#um-form-active').prop('checked', user.is_active == 1);
            // Prevent an admin from locking themselves out via this form.
            const isSelf = user.id === currentUserId;
            $('#um-form-role').prop('disabled', isSelf);
            $('#um-form-active').prop('disabled', isSelf);
        } else {
            $('#um-modal-title').text('New user');
            $('#um-form-id').val('');
            $('#um-form-role').prop('disabled', false).val('staff');
            $('#um-form-active').prop('disabled', false).prop('checked', true);
        }
        $('#um-modal').prop('hidden', false);
    }

    function closeModal() {
        $('#um-modal').prop('hidden', true);
    }

    $('#btn-new-user').on('click', function () { openModal(null); });
    $('#um-cancel-btn').on('click', closeModal);
    $('#um-modal').on('click', function (e) {
        if (e.target.id === 'um-modal') closeModal();
    });

    $('#um-table-wrap').on('click', '.um-edit-btn', function () {
        const id = parseInt($(this).data('id'), 10);
        const user = (window._umUsers || []).find(u => u.id === id);
        if (user) openModal(user);
    });

    $('#um-form').on('submit', function (e) {
        e.preventDefault();
        const $error  = $('#um-form-error').prop('hidden', true);
        const $submit = $(this).find('button[type="submit"]').prop('disabled', true);

        const id = $('#um-form-id').val();
        const payload = {
            name:  $('#um-form-name').val(),
            email: $('#um-form-email').val(),
            role:  $('#um-form-role').val(),
        };

        const action = id ? 'update' : 'create';
        if (id) {
            payload.id = parseInt(id, 10);
            payload.is_active = $('#um-form-active').is(':checked');
        }

        apiPost(action, payload)
            .done(function () {
                closeModal();
                loadUsers();
            })
            .fail(function (jqXHR) {
                $error.text(errorMessage(jqXHR, 'Could not save user.')).prop('hidden', false);
            })
            .always(function () {
                $submit.prop('disabled', false);
            });
    });

    loadUsers();

});
