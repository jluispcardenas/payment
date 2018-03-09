# Accept payments from bitcoin

This package enables you to accept payments from bitcoin. 

## Installation
The package can be installed through Composer:

```
composer require jlp/payment
```

This service provider must be installed:

```php

//for laravel <=4.2: app/config/app.php

'providers' => [
    ...
    'Jlp\Payment\PaymentServiceProvider'
    ...
];
```

## Use example

```php
use Jlp\Payment\Gateways\Bitcoin\PaymentGateway as BitcoinPaymentGateway;

class CheckoutConfirmOrderController extends BaseController {


    /**
     * @var PaymentGateway
     */
    protected $paymentGateway;

    public function __construct(.. PaymentGateway $paymentGateway ...)
    {
        ...
        $this->paymentGateway = $paymentGateway;
        ...
    }
```


```php
public function showOrderDetails()
    {
        $order = Order::findOrFail(1);
		
		$this->paymentGateway->setOrder($order);
		
		$this->paymentGateway->preparePayment();
		
		$paymentGateway = $this->paymentGateway;
		
		return view('store.payment')->with(compact('order', 'paymentGateway'));
	 }
```

## Remarks
This module is derived from the WooComerce module for bitcoin: http://www.bitcoinway.com/
