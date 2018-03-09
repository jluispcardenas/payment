<?php
namespace Jlp\Payment;

/**
 * Interface 
 */
interface IGateway
{
    public function setOrder(IOrder $order);

	public function preparePayment();
	
    public function getPaymentForm($attributes = []);
		
}