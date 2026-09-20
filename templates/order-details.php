<table class="wc-order-totals" style="border-top: 1px solid #999; margin-top:12px; padding-top:12px">
    <tbody>
        <tr>
            <td class="label"><h3 style="margin:0"><?php echo __("Payment Status", 'upayments'); ?>:</h3></td>
            <td style="width:1%;"></td>
            <td class="total">
                <span class="woocommerce-Price-amount amount"><strong><?php echo $payment_status; ?></strong></span>
            </td>
        </tr>
        <tr>
            <td class="label"><h3 style="margin:0"><?php echo __("UPayment ID", 'upayments'); ?>:</h3></td>
            <td style="width:1%;"></td>
            <td class="total">
                <span class="woocommerce-Price-amount amount">
                    <strong>
                    <?php echo $upayment_id; ?>
                    </strong>
                </span>
            </td>
        </tr>
                    
    </tbody>
</table>
