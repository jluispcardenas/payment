<?php
return
    [
        'bitcoin' =>
            [
                'wallet' => env('PAYMENT_BITCOIN_WALLET'),
				'store_currency' => env('STORE_CURRENCY'),
				'exchange_rate_type' => env('EXCHANGE_RATE_TYPE'),
				'reuse_expired_addresses' => 1,
            ]
    ];