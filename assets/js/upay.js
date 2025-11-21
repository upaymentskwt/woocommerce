jQuery(function ($) {
    // Listen for page load and DOM ready
    $(document).ready(function () {
        checkApplePayAvailability();
        // Check after a short delay to handle redirection (if needed)
        setTimeout(function () {
            checkApplePayAvailability();
        }, 500); // Adjust the delay time if needed
    });

    // Listen for AJAX Complete event (to handle cases like redirection)
    $(document).ajaxComplete(function () {
        checkApplePayAvailability();
    });

    function checkApplePayAvailability() {
        justEat = {
            applePay: {
                supportedByDevice: function () {
                    return "ApplePaySession" in window;
                },
                getMerchantIdentifier: function () {
                    return "merchant.com.upayments.ustore";
                }
            }
        };

        var merchantIdentifier = justEat.applePay.getMerchantIdentifier();
        if (merchantIdentifier && justEat.applePay.supportedByDevice()) {
            // Determine whether to display the Apple Pay button. See this link for details
            // on the two different approaches: https://developer.apple.com/documentation/applepayjs/checking_if_apple_pay_is_available
            if (ApplePaySession.canMakePayments() === true) {
                console.log('apple pay available');
            } else {
                ApplePaySession.canMakePaymentsWithActiveCard(merchantIdentifier).then(function (canMakePayments) {
                    if (canMakePayments === true) {
                        console.log('apple pay available');
                    } else {
                        console.log('apple not available');
                        // $('#upay-button-apple-pay').hide();
                    }
                });
            }
        } else {
            console.log('apple not available');
            // $('#upay-button-apple-pay').hide();

        }
    }
});

