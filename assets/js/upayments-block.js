(function ( wc, wp ) {
    const init = () => {
        if ( !window.wc || !wc.wcBlocksRegistry ) {
            return setTimeout(init, 100);
        }

        const { registerPaymentMethod } = wc.wcBlocksRegistry;

        const settings = window.wc.wcSettings.getPaymentMethodData('upayments') || {};
        const {
            is_whitelabled,
            payment_icons,
            saved_cards,
            is_logged_in,
            save_card_enabled,
            save_card_toggle_on,
            cart_total,
            currency_display,
            is_subscription_enabled,
            product_type,
            plugin_url,
            translation
        } = settings;
        console.log(payment_icons);
        
        
        const Content = (props) => {
            const { useDispatch, select } = wp.data;
            const { useEffect, useState, createElement } = wp.element;
            const { setExtensionData } = useDispatch('wc/store/checkout');
            const hasCustomTypeProduct = Array.isArray(product_type) && product_type.some(product => product.type === 'custom_type');

            const NAMESPACE = 'upayments';
            
            // Get current data from the Store API
            const checkoutData = select('wc/store/checkout').getExtensionData() || {};
            const upayData = checkoutData[NAMESPACE] || {};            

            useEffect(() => {
                setExtensionData(NAMESPACE, {
                    ...upayData,
                    upay_subscription_plan: 'one_time',
                    upay_subscription_interval: '0'
                });
            }, []);

            // --- 1. TOAST STATE ---
            const [toast, setToast] = useState({ message: '', show: false });

            const showToast = (msg) => {
                setToast({ message: msg, show: true });
                setTimeout(() => setToast({ message: '', show: false }), 3500);
            };

            // Subscription Options Logic
            const optionsData = {
                daily: { "1": "Every Day" },
                weekly: { "1": "Every Week", "2": "Every 2 Weeks", "3": "Every 3 Weeks" },
                monthly: { "1": "Every Month", "2": "Every 2 Months" },
                quarterly: { "1": "Every Quarter", "2": "Every 2 Quarters", "3": "Every 3 Quarters" },
                yearly: { "1": "Every Year" }
            };

            // --- Helpers ---
            const updateCheckout = (newData) => {
                const currentData = wp.data.select('wc/store/checkout').getExtensionData('upayments') || {};
                setExtensionData(NAMESPACE, {
                    ...currentData,
                    ...newData
                });
            };            

            const handleSubscriptionChange = (plan, interval) => {
                const finalInterval = (plan === upayData.upay_subscription_plan) ? interval : '';
                setExtensionData(NAMESPACE, {
                    ...upayData,
                    upay_subscription_plan: plan,
                    upay_subscription_interval: finalInterval
                });
            };

            const handleMethodClick = (type, token = null) => {
                updateCheckout({
                    upayment_payment_type: type,
                    card_token: token,
                    save_card: (save_card_toggle_on && type === 'cc') ? '1' : '0'
                });
                // Example of toast usage:
                if(token) showToast("Saved card selected");
            };

            const toggleSaveCard = () => {
                let checkbox = document.getElementById('chkSaveCard');
                const currentSaveValue = upayData.save_card === '1';

                if (is_logged_in === false) {
                    checkbox.checked = false;
                    updateCheckout({ save_card: '0'});
                    showToast('Please Login to use the Save Card feature.');
                    return;
                }
                updateCheckout({save_card: currentSaveValue ? '0' : '1'});
            };
            return createElement(
                'div',
                { className: 'upay-payment-container' },

                // --- SECTION: TOASTER RENDERING ---
                toast.show && createElement('div', { 
                    className: 'wc-toast show',
                    style: {
                        position: 'fixed', top: '30px', right: '30px', background: '#F23232',
                        color: '#fff', padding: '12px 18px', borderRadius: '6px', zIndex: 99999,
                        boxShadow: '0 4px 12px rgba(0,0,0,0.15)', transition: 'all 0.3s ease'
                    }
                }, toast.message),

                // --- SECTION 1: SUBSCRIPTION FIELDS ---
                is_subscription_enabled && hasCustomTypeProduct && createElement(
                    'div',
                    { className: 'upay-subscription-wrapper', style: { marginBottom: '20px', padding: '15px', background: '#f9f9f9', borderRadius: '8px', border: '1px solid #eee' } },
                    createElement('label', { style: { display: 'block', fontWeight: 'bold', marginBottom: '5px' } }, 
                        'Purchase Type ', createElement('span', { style: { color: 'red' } }, '*')
                    ),
                    createElement('select', {
                        value: upayData.upay_subscription_plan || 'one_time',
                        className: 'wc-block-components-select__input',
                        style: { width: '100%', padding: '10px', marginBottom: '15px' },
                        onChange: (e) => handleSubscriptionChange(e.target.value, upayData.upay_subscription_interval)
                    },
                        createElement('option', { value: 'one_time' }, 'One-time'),
                        createElement('option', { value: 'daily' }, 'Daily Subscription'),
                        createElement('option', { value: 'weekly' }, 'Weekly Subscription'),
                        createElement('option', { value: 'monthly' }, 'Monthly Subscription'),
                        createElement('option', { value: 'quarterly' }, 'Quarterly Subscription'),
                        createElement('option', { value: 'yearly' }, 'Yearly Subscription')
                    ),
                    upayData.upay_subscription_plan && upayData.upay_subscription_plan !== 'one_time' && createElement('div', {},
                        createElement('label', { style: { display: 'block', fontWeight: 'bold', marginBottom: '5px' } }, 'Billing Interval ', createElement('span', { style: { color: 'red' } }, '*')),
                        createElement('select', {
                            value: upayData.upay_subscription_interval || '',
                            className: 'wc-block-components-select__input',
                            style: { width: '100%', padding: '10px' },
                            onChange: (e) => handleSubscriptionChange(upayData.upay_subscription_plan, e.target.value)
                        },
                            createElement('option', { value: '' }, 'Select interval'),
                            optionsData[upayData.upay_subscription_plan] && Object.entries(optionsData[upayData.upay_subscription_plan]).map(([val, text]) => (
                                createElement('option', { key: val, value: val }, text)
                            ))
                        )
                    )
                ),

                // --- SECTION 2: PAYMENT METHODS COMPARTMENTS ---
                createElement('div', { className: 'form-row form-row-wide' },
                    is_whitelabled ? createElement('div', { className: 'payment-sections' },
                        
                        // COMPARTMENT A: Pay Using Saved Cards
                        is_logged_in && Array.isArray(saved_cards) && saved_cards.length > 0 && [
                            createElement('h3', { 
                                    key: 'title-saved', 
                                    style: { 
                                        fontSize: '16px', 
                                        fontWeight: 'bold', 
                                        margin: '20px 0 10px' 
                                    } 
                                }, 'Pay Using Saved Cards'
                            ),
                            createElement('div', { 
                                key: 'list-saved', 
                                className: 'saved-cards-group'
                            },
                            saved_cards.map((card, index) => 
                                {
                                    const token = card.token;
                                    return createElement('button', 
                                        {
                                            key: token || index,
                                            type: 'button',
                                            className: `upay-payment-method ${upayData.card_token === token ? 'active' : ''}`,
                                            onClick: () => handleMethodClick('cc', token),
                                                style: { 
                                                    display: 'flex', 
                                                    width: '100%', 
                                                    padding: '12px', 
                                                    marginBottom: '8px', 
                                                    alignItems: 'center', 
                                                    borderRadius: '4px', 
                                                    background: '#fff', 
                                                    border: upayData.card_token === token ? '2px solid #007cba' : '1px solid #ccc',
                                                    cursor: 'pointer'
                                                }
                                        },
                                        createElement('span', { className: 'payment-method-icon' }, 
                                            createElement('img', { 
                                                src: `${plugin_url}assets/images/cc.png`, 
                                                style: { 
                                                    height: '24px' 
                                                } 
                                            })
                                        ),
                                        createElement('span', { 
                                            style: { 
                                                marginLeft: '10px', 
                                                flexGrow: 1, 
                                                textAlign: 'left', 
                                                fontSize: '14px' 
                                            }
                                        }, `${card.number} (${card.brand})`),
                                        createElement('span', { 
                                            style: { 
                                                marginLeft: 'auto', 
                                                fontWeight: '600' 
                                            } 
                                        }, `${cart_total} ${currency_display}`),
                                        createElement('i', { 
                                            className: 'fa fa-chevron-right', 
                                            style: { 
                                                marginLeft: '10px' 
                                            } 
                                        })
                                    );
                                })
                            )
                        ],

                        // COMPARTMENT B: Choose Payment Method
                        createElement('h3', { 
                            style: { 
                                fontSize: '16px', 
                                fontWeight: 'bold', 
                                margin: '25px 0 10px'
                            } 
                        }, 'Choose Payment Method'),
                        createElement('div', { 
                            className: 'normal-methods-group' 
                        },
                        Object.entries(payment_icons).map(([key, label]) => (
                            createElement('button', 
                                    {
                                        key: key,
                                        type: 'button',
                                        className: `upay-payment-method ${upayData.upayment_payment_type === key && !upayData.card_token ? 'active' : ''}`,
                                        onClick: () => handleMethodClick(key),
                                        style: { 
                                            display: 'flex', 
                                            width: '100%', 
                                            padding: '12px', 
                                            marginBottom: '8px', 
                                            alignItems: 'center', 
                                            borderRadius: '4px', 
                                            background: '#fff', 
                                            border: (upayData.upayment_payment_type === key && !upayData.card_token) ? '2px solid #007cba' : '1px solid #ccc' 
                                        }
                                    },
                                    createElement('span', { className: 'payment-method-icon' }, 
                                        (() => {
                                            if (key === 'apple-pay-knet') {
                                                return [
                                                    createElement('img', { 
                                                        key: 'apple', 
                                                        src: `${plugin_url}assets/images/apple-pay.png`, 
                                                        style: { 
                                                            height: '24px', 
                                                            marginRight: '5px' 
                                                        } 
                                                    }),
                                                    createElement('img', { 
                                                        key: 'knet', 
                                                        src: `${plugin_url}assets/images/knet.png`, 
                                                        style: { 
                                                            height: '24px' 
                                                        } 
                                                    })
                                                ];
                                            } else if (key === 'apple-pay') {
                                                return [
                                                    createElement('img', { 
                                                        key: 'apple', 
                                                        src: `${plugin_url}assets/images/apple-pay.png`, 
                                                        style: { 
                                                            height: '24px', 
                                                            marginRight: '5px' 
                                                        } 
                                                    }),
                                                    createElement('img', { 
                                                        key: 'cc', 
                                                        src: `${plugin_url}assets/images/cc.png`, 
                                                        style: { 
                                                            height: '24px' 
                                                        } 
                                                    })
                                                ];
                                            }
                                            return createElement('img', { 
                                                src: `${plugin_url}assets/images/${key}.png`, 
                                                style: { 
                                                    height: '24px' 
                                                } 
                                            });
                                        })()
                                    ),
                                    createElement('span', { 
                                        style: { 
                                            marginLeft: '10px', 
                                            fontWeight: '600' 
                                        } 
                                    }, label),
                                    createElement('span', { 
                                        style: { 
                                            marginLeft: 'auto', 
                                            fontWeight: '600' 
                                        } 
                                    }, `${cart_total} ${currency_display}`),
                                    createElement('i', { 
                                        className: 'fa fa-chevron-right', 
                                        style: { 
                                            marginLeft: '10px' 
                                        } 
                                    })
                                )
                            ))
                        ),

                        // Save Card Toggle for New Cards
                        save_card_enabled && upayData.upayment_payment_type === 'cc' && !upayData.card_token && (
                            createElement('div', { 
                                    style: { 
                                        display: 'flex', 
                                        justifyContent: 'space-between', 
                                        alignItems: 'center', 
                                        padding: '15px', 
                                        borderTop: '1px solid #eee', 
                                        marginTop: '10px' 
                                    } 
                                },
                                createElement('label', { 
                                    style: { 
                                        fontSize: '0.9em' 
                                    } 
                                }, translation.save_card_label || 'Save card for future use?'),
                                createElement('label', { 
                                        className: 'switch' 
                                    },
                                    createElement('input', { 
                                        type: 'checkbox', 
                                        id: 'chkSaveCard', 
                                        checked: save_card_toggle_on, 
                                        onChange: toggleSaveCard 
                                    }),
                                    createElement('span', { className: 'slider round' })
                                )
                            )
                        )
                    ) : (
                        // Grouped View (If not Whitelabeled)
                        createElement('div', { className: 'payment-buttons' },
                            createElement('button', {
                                type: 'button', className: 'upay-payment-method',
                                style: { display: 'flex', width: '100%', padding: '15px', alignItems: 'center', border: '1px solid #ccc', borderRadius: '4px', background: '#fff' }
                            },
                                Object.keys(payment_icons).map(key => (
                                    key !== 'apple-pay-knet' && createElement('span', { key, style: { marginRight: '8px' } },
                                        createElement('img', { src: `${plugin_url}assets/images/${key}.png`, style: { height: '22px' } })
                                    )
                                )),
                                createElement('span', { style: { marginLeft: 'auto', fontWeight: '600' } }, `${cart_total} ${currency_display}`),
                                createElement('i', { className: 'fa fa-chevron-right', style: { marginLeft: '10px' } })
                            )
                        )
                    )
                )
            );
        };
        
        registerPaymentMethod({
            name: 'upayments',
            label: 'UPayments',

            content: wp.element.createElement(Content),
            edit: wp.element.createElement(Content),

            canMakePayment: () => {
                return true;
            },

            ariaLabel: 'UPayments',

            supports: {
                features: [ 'products' ],
            },
            
            onPaymentMethodChange: () => {
                console.log('UPayments selected');
                return true;
            }
        });
    };

    init();

})( window.wc, window.wp );
