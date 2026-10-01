jQuery(document).ready(function($) {
    // Define the input selectors
    let $newDesignCheckbox = $('#woocommerce_upayments_use_new_design');
    let $saveCardCheckbox = $('#woocommerce_upayments_enable_save_card');
    
    // We need to disable the entire row (<tr>) containing the checkbox for better visibility
    let $saveCardRow = $saveCardCheckbox.closest('tr');

    /**
     * Toggles the disabled state of the Save Card checkbox based on the New Design state.
     */
    function toggleSaveCardState() {
        if ($newDesignCheckbox.is(':checked')) {
            // New Design is ON: Enable Save Card feature
            $saveCardCheckbox.prop('disabled', false).prop('checked', $saveCardCheckbox.data('original-checked'));
            $saveCardRow.removeClass('upayments-disabled-setting');
        } else {
            // New Design is OFF (Old Design is ON): Disable Save Card feature
            
            // Store the current state before forcing it off (in case the user turns the New Design back on)
            if (typeof $saveCardCheckbox.data('original-checked') === 'undefined') {
                $saveCardCheckbox.data('original-checked', $saveCardCheckbox.prop('checked'));
            }
            
            // Disable the checkbox and uncheck it
            $saveCardCheckbox.prop('disabled', true).prop('checked', false);
            $saveCardRow.addClass('upayments-disabled-setting');
        }
    }

    // Initialize state on page load
    toggleSaveCardState();

    // Bind change listener to the New Design checkbox
    $newDesignCheckbox.on('change', toggleSaveCardState);


    let $multiMerchantCheckbox = $('#woocommerce_upayments_enable_multimerchant');
    let $multiMerchantRow = $('.upayments-multimerchant-repeater').closest('tr');

    function toggleMultiMerchantState() {
        if ($multiMerchantCheckbox.is(':checked')) {
            $multiMerchantRow.show();
        } else {
            $multiMerchantRow.hide();
        }
    }

    // Initial state
    toggleMultiMerchantState();

    // Bind change listener to the Multimerchant checkbox
    $multiMerchantCheckbox.on('change', toggleMultiMerchantState);
});