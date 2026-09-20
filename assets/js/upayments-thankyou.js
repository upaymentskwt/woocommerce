jQuery(function ($) {
    if (typeof upaymentsData === 'undefined') {
        return;
    }

    let is_status_received = false;
    let pollCount = 0;
    const maxPolls = 30; // Stop polling after 60 seconds (30 * 2000ms)

    $('.upayment-status-holder').insertAfter('.woocommerce-order-overview__payment-method');
    $('.upayment-id-holder').insertAfter('.woocommerce-order-overview__payment-status');

    if (upaymentsData.i18n_order_status) {
        $('.entry-header .entry-title').text(upaymentsData.i18n_order_status);
    }

    $('.woocommerce-thankyou-order-received, .woocommerce-thankyou-order-details, .woocommerce-order-details, .woocommerce-customer-details').hide();

    function show_upayments_status(type) {
        $('.payment-panel-wait').hide();
        $('.woocommerce-order-details, .woocommerce-customer-details').show();

        if (type && type.length > 0) {
            $('.payment-panel-' + type).show();
        }
    }

    function check_upayments_payment_status() {
        if (is_status_received || pollCount >= maxPolls) {
            return;
        }

        pollCount++;

        $.getJSON(upaymentsData.ajax_url, {
            action: 'upayments_get_payment_status',
            security: upaymentsData.nonce,
            order_id: upaymentsData.order_id,
            order_key: upaymentsData.order_key
        })
        .done(function (response) {
            if (!response.success) {
                show_upayments_status('error');
                is_status_received = true;
                return;
            }

            const status = response.data.status;

            if (status === 'wait' || status === 'pending') {
                setTimeout(check_upayments_payment_status, 2000);
            } else if (status === 'completed') {
                show_upayments_status('completed');
                is_status_received = true;
                window.location.reload(); // Refresh to show native WooCommerce completed details
            } else if (status === 'failed') {
                show_upayments_status('failed');
                is_status_received = true;
            } else {
                show_upayments_status('error');
                is_status_received = true;
            }
        })
        .fail(function () {
            if (pollCount < maxPolls) {
                setTimeout(check_upayments_payment_status, 3000);
            } else {
                show_upayments_status('error');
            }
        });
    }

    check_upayments_payment_status();
});