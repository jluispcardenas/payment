<?php
namespace Jlp\Payment;

interface IOrder
{
    public function getOrderId();
	
    public function getAmount();
    
	public function getDescription();
	
    public function getCustomerEmail();
    
	public function setOrderMeta($gateway, $meta);
	
	static function processPaymentCompleted($order_id, $balance);
}