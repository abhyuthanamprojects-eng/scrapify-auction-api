<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Razorpay Test Checkout</title>
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; padding: 40px 20px; }
        .container { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.1); padding: 32px; }
        h1 { font-size: 22px; margin-bottom: 8px; }
        .subtitle { color: #666; font-size: 14px; margin-bottom: 24px; }
        label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px; color: #333; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 15px; margin-bottom: 16px; }
        input:focus, select:focus { outline: none; border-color: #528ff0; box-shadow: 0 0 0 3px rgba(82,143,240,.15); }
        .btn { width: 100%; padding: 14px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: .2s; }
        .btn-pay { background: #528ff0; color: #fff; }
        .btn-pay:hover { background: #3d7be0; }
        .btn-pay:disabled { background: #ccc; cursor: not-allowed; }
        .log { margin-top: 24px; background: #f8f8f8; border-radius: 8px; padding: 16px; font-family: monospace; font-size: 13px; max-height: 300px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; display: none; }
        .log.visible { display: block; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-bottom: 16px; }
        .badge-test { background: #fff3cd; color: #856404; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="container">
        <span class="badge badge-test">TEST MODE</span>
        <h1>Scrapify Payment Test</h1>
        <p class="subtitle">Razorpay Standard Checkout — test transaction</p>

        <label for="amount">Amount (INR)</label>
        <input type="number" id="amount" value="100" min="1" step="1" placeholder="Enter amount in rupees">

        <label for="purpose">Purpose</label>
        <select id="purpose">
            <option value="wallet_topup">Wallet Top-up</option>
            <option value="registration">Registration Fee</option>
        </select>

        <button class="btn btn-pay" id="payBtn" onclick="startPayment()">Pay with Razorpay</button>

        <div class="log" id="log"></div>
    </div>

    <script>
        const BASE = '{{ url("/") }}';
        const logEl = document.getElementById('log');

        function log(msg) {
            logEl.classList.add('visible');
            logEl.textContent += new Date().toLocaleTimeString() + '  ' + msg + '\n';
            logEl.scrollTop = logEl.scrollHeight;
        }

        async function startPayment() {
            const btn = document.getElementById('payBtn');
            const amount = parseFloat(document.getElementById('amount').value);
            const purpose = document.getElementById('purpose').value;

            if (!amount || amount < 1) { alert('Minimum ₹1'); return; }

            btn.disabled = true;
            btn.textContent = 'Creating order...';
            log('Creating Razorpay order for ₹' + amount + ' (' + purpose + ')');

            try {
                const res = await fetch(BASE + '/api/v1/test/razorpay/create-order', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ amount, purpose }),
                });

                const json = await res.json();
                if (!res.ok) {
                    log('ERROR: ' + JSON.stringify(json));
                    btn.disabled = false;
                    btn.textContent = 'Pay with Razorpay';
                    return;
                }

                const data = json.data;
                log('Order created: ' + data.razorpay_order_id);
                log('Amount: ' + (data.amount / 100) + ' ' + data.currency);

                const options = {
                    key: data.key_id,
                    amount: data.amount,
                    currency: data.currency,
                    name: 'Scrapify Auction',
                    description: purpose.replace('_', ' ').toUpperCase(),
                    order_id: data.razorpay_order_id,
                    prefill: data.prefill || {},
                    theme: { color: '#528ff0' },
                    handler: async function(response) {
                        log('Payment success callback received');
                        log('Payment ID: ' + response.razorpay_payment_id);
                        log('Order ID: ' + response.razorpay_order_id);
                        log('Signature: ' + response.razorpay_signature);
                        log('Verifying signature...');

                        try {
                            const verifyRes = await fetch(BASE + '/api/v1/test/razorpay/verify', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                                body: JSON.stringify({
                                    razorpay_payment_id: response.razorpay_payment_id,
                                    razorpay_order_id: response.razorpay_order_id,
                                    razorpay_signature: response.razorpay_signature,
                                    purpose: purpose,
                                }),
                            });

                            const verifyJson = await verifyRes.json();
                            if (verifyRes.ok) {
                                log('VERIFIED! Payment confirmed.');
                                log(JSON.stringify(verifyJson.data, null, 2));
                                btn.textContent = 'Payment Successful!';
                                btn.style.background = '#28a745';
                            } else {
                                log('VERIFICATION FAILED: ' + JSON.stringify(verifyJson));
                                btn.textContent = 'Verification Failed';
                                btn.style.background = '#dc3545';
                            }
                        } catch (e) {
                            log('Verify request error: ' + e.message);
                        }
                    },
                    modal: {
                        ondismiss: function() {
                            log('Payment modal dismissed by user');
                            btn.disabled = false;
                            btn.textContent = 'Pay with Razorpay';
                        },
                    },
                };

                log('Opening Razorpay checkout modal...');
                const rzp = new Razorpay(options);
                rzp.on('payment.failed', function(response) {
                    log('Payment FAILED: ' + response.error.description);
                    log('Code: ' + response.error.code + ', Reason: ' + response.error.reason);
                    btn.disabled = false;
                    btn.textContent = 'Retry Payment';
                });
                rzp.open();

            } catch (e) {
                log('Request error: ' + e.message);
                btn.disabled = false;
                btn.textContent = 'Pay with Razorpay';
            }
        }
    </script>
</body>
</html>
