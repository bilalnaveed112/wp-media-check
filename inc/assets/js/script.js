jQuery(document).ready(function($) {
    const nonce = wpmdc_ajax_object.security;

    const checkImageUsage = (id) => {
        // Perform an AJAX request to check if the attachment is used
        $.post(ajaxurl, {
            action: 'check_attachment_usage',
            attachment_id: id,
            security: nonce
        }, function(response) {
            if (response.data.find) {
                $('button.delete-attachment').prop('disabled', true);
                $("button.delete-attachment").css("color", "unset");
                $("button.delete-attachment").css("cursor", "not-allowed");
            }
        });
    }

    const restrictDeleteButton = (e) => {
        var attachmentId = $(e.target).closest('.attachment').data('id');

        // Perform an AJAX request to check if the attachment is used
        checkImageUsage(attachmentId);
    }

    (function bindEvents() {
        // Bind the function to the media library's grid view events
        $(document).on('click', '.attachment', restrictDeleteButton);

        $(document).ajaxComplete(function(event, xhr, settings) {
            // Check if the action is 'query-attachments'
            if (settings.data && settings.data.includes('action=query-attachments')) {
                const params = new URLSearchParams(settings.data);
                const attachmentId = params.get('query[item]');
                if (attachmentId) {
                    restrictDeleteButton(attachmentId);
                }
            }
        });

    })();
});