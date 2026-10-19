/* global wp, jQuery */
(function ($) {
    'use strict';
    $('#oa-choose-logo').on('click', function () {
        const picker = wp.media({title: 'Choose client logo', library: {type: 'image'}, multiple: false});
        picker.on('select', function () {
            const image = picker.state().get('selection').first().toJSON();
            $('#oa-logo-id').val(image.id);
            const preview = document.createElement('img');
            preview.src = image.sizes?.thumbnail?.url || image.url;
            preview.alt = '';
            preview.style.maxWidth = '150px';
            $('#oa-logo-preview').empty().append(preview);
        });
        picker.open();
    });
    $('#oa-remove-logo').on('click', function () {
        $('#oa-logo-id').val('0');
        $('#oa-logo-preview').empty();
    });
    $('#oa-test-connection').on('click', async function () {
        this.disabled = true;
        const status = document.getElementById('oa-test-status');
        status.textContent = 'Testing saved settings…';
        try {
            const response = await fetch(this.dataset.url, {method: 'POST', credentials: 'same-origin', headers: {'X-WP-Nonce': this.dataset.nonce}});
            const data = await response.json();
            status.textContent = data.message || (response.ok ? 'Connected.' : 'The connection could not be verified.');
        } catch (_) {
            status.textContent = 'The connection could not be verified. Reload the page and try again.';
        } finally {
            this.disabled = false;
        }
    });
})(jQuery);
