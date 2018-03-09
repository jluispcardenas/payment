<?php
namespace Jlp\Payment;

use Illuminate\Support\ServiceProvider;
use Jlp\Payment\Gateways\Bitcoin\PaymentGateway as BitcoinPaymentGateway;

class PaymentServiceProvider extends ServiceProvider
{
    public function boot()
    {
		$this->mergeConfigFrom(__DIR__.'/../config/payment.php', 'payment');
		
		$this->publishes([
			__DIR__.'/../config/payment.php' => base_path('config/payment.php'),
		], 'config');
		
    }
	
    public function register()
    {
        $this->app->bind(PaymentGateway::class, BitcoinPaymentGateway::class);
    }
}