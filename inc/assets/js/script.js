jQuery(document).ready(function($) {
    const nonce = wpmdc_ajax_object.security;
    const is_process_running = wpmdc_ajax_object.is_process_running;
    var processed_images = wpmdc_ajax_object.processed_images;
    var $notification = jQuery('.wpmdc_notification');
    const interval = 3000;
    var checkStatusInterval;
    var isNumberCounterLoaded = false;
    var lastProcessed;

    const checkImageUsage = (id) => {
        // Perform an AJAX request to check if the attachment is used
        $.post(ajaxurl, {
            action: 'check_attachment_usage',
            attachment_id: id,
            security: nonce
        }, function(response) {
            if (response.data?.find) {
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

    const updateProgressBar = () => {

        percentage = jQuery('.wpmdc_progress_bar').text();
        numericValue = percentage.replace('%', '');
        percentage = parseFloat(numericValue);
        if (percentage < 100) {
            let data = {
                action: 'check_progress_status',
                security: nonce,
            };
            $.ajax({
                type: 'POST',
                url: ajaxurl,
                data,
                success(response){
                    if ( response.success ) {
                        percentage = Math.round(((response.data.progress.processed / response.data.progress.total) * 100));
                        if ( lastProcessed != response.data.progress.processed ) {
                            updateProgressCircle( percentage );
                            jQuery('#processed-images').text(response.data.progress.processed);
                            jQuery('#pending-images').text(response.data.progress.pending);
                            jQuery('.time_estimation').text(response.data.estimated_time.total_estimated_time);
                            jQuery('.time_unit').text(response.data.estimated_time.time_unit);
                            lastProcessed = response.data.progress.processed;
                        } else {
                            clearInterval( checkStatusInterval );
                        }
                        if ( response.data.progress.processed == response.data.progress.total ) {
                            clearInterval( checkStatusInterval );
                            $('.process_all_images').prop('disabled', false);
                            $('.cancel_process').addClass('wpmdc_d_none');
                        }
                    }
                },
                error(response){
                    console.log(response);
                }
            });
        } else {
            clearInterval( checkStatusInterval );
        }
    }

    const processAllImages = (e) => {
        e.preventDefault();
        $('.process_all_images').prop('disabled', true);
        const data = {
            action: 'process_all_images',
            security: nonce,
        };
        $.ajax({
            type: 'POST',
            url: ajaxurl,
            data,
            success(response){
                if ( response.success ){
                    if ( response.data.status ) {
                        checkStatusInterval = setInterval(updateProgressBar, 10000);
                        $('.cancel_process').removeClass('wpmdc_d_none');
                    } else {
                        $('.process_all_images').prop('disabled', false);
                    }
                    $('.wpmdc_notification p').text(response.data.message);
                    $notification
                    .animate({ opacity: 1 }, 400)
                    .delay(4000)
                    .animate({ opacity: 0 }, 400, function() { });
                }
            },
            error(xhr) {
                console.log(xhr);
            }
        });
    }

    const cancelProcessingImages = (e) => {
        e.preventDefault();
        $(e.target).prop('disabled', true);
        let startCancelMsg = $('.wpmdc_start_cancel_msg').val();
            $('.wpmdc_notification p').text(startCancelMsg);
            $notification
            .animate({ opacity: 1 }, 400);
            const data = {
                action: 'cancel_image_processing',
                security: nonce,
            };
        $.ajax({
            type: 'POST',
            url: ajaxurl,
            data,
            success(response){
                $('.cancel_process').addClass('wpmdc_d_none');
                $('.process_all_images').prop('disabled', false);
                $('.cancel_process').prop('disabled', false);
                clearInterval( checkStatusInterval );
                $('.wpmdc_notification p').text(response.data);
                $notification
                .animate({ opacity: 1 }, 400)
                .delay(4000)
                .animate({ opacity: 0 }, 400, function() { });
            },
            error(xhr) {
                console.log(xhr);
            }
        });
    }

    const updateProgressCircle = ( percentage ) => {
        percentage = Math.max(0, Math.min(100, percentage));
        const radius = 42;
        const circumference = 2 * Math.PI * radius;
        const strokeDashoffset = circumference - (percentage / 100) * circumference;
        $('.wpmdc_score__progress--circle').css('stroke-dashoffset', strokeDashoffset);
        if ( !isNumberCounterLoaded ) {
            $('.wpmdc_progress_bar').prop('Counter', 0).animate({
                Counter: percentage
            }, {
                duration: 2000,
                easing: 'swing',
                step: function (now) {
                    // Update the text
                    $(this).text(Math.ceil(now) + '%');
                }
            });
            isNumberCounterLoaded = true;
        } else {
            $('.wpmdc_progress_bar').text(percentage + '%');
        }
    }

    (function bindEvents() {

        if ( processed_images > 0 ) {
            updateProgressCircle( processed_images );
        }
        // Bind the function to the media library's grid view events
        $(document).on('click', '.attachment', restrictDeleteButton);

        $(document).on('click', '.process_all_images', processAllImages);
        
        $(document).on('click', '.cancel_process', cancelProcessingImages);

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

        if ( is_process_running ) {
            checkStatusInterval = setInterval(updateProgressBar, interval);
        }

    })();
    // wp.media.editor.insert = function (html) {
    //     let altText = '';

    //     // Parse the HTML to extract the alt attribute
    //     const altAttr = $(html).find('img').attr('alt');
    //     if (altAttr) {
    //         altText = altAttr.trim();
    //     }

    //     // If Alt text is missing, prevent image insertion and show an alert
    //     if (!altText) {
    //         alert('You cannot insert an image without an Alt text.');
    //         return; // Prevent insertion
    //     }

    //     return wp.media.editor.insert(html); // Allow insertion
    // };
});