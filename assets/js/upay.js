jQuery(function ($) {

	function hidePlaceOrderButtonIfNeeded() {
		var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
		if (selectedPaymentMethod === 'upayments') { // Replace 'upayments' with the ID of your custom payment method
			$('button#place_order').hide();
		} else {
			$('button#place_order').show();
		}
	}

	function handlePaymentMethodChange() {
		hidePlaceOrderButtonIfNeeded();
	}

	$('form.checkout').on('change', 'input[name="payment_method"]', function () {
		handlePaymentMethodChange();
	});

	// Listen for page load and DOM ready
	$(document).ready(function () {
		hidePlaceOrderButtonIfNeeded(); // Check on DOM ready
		checkApplePayAvailability();
		// Check after a short delay to handle redirection (if needed)
		setTimeout(function () {
			hidePlaceOrderButtonIfNeeded();
			checkApplePayAvailability();
		}, 500); // Adjust the delay time if needed
	});

	// Listen for AJAX Complete event (to handle cases like redirection)
	$(document).ajaxComplete(function () {
		hidePlaceOrderButtonIfNeeded();
		checkApplePayAvailability();
	});

	var customPaymentMethodId = 'upayments';

	// Check if the form exists and the chosen payment method is not already set
	if ($('form.checkout').length > 0 && $('input[name="payment_method"]:checked').val() !== customPaymentMethodId) {
		// Trigger click event on the desired payment method
		$('input[name="payment_method"][value="' + customPaymentMethodId + '"]').click();
	}

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

function submitUpayButton(buttonValue) {
	jQuery('#upayment_payment_type').val(buttonValue);
	jQuery('form.checkout').submit();

}

