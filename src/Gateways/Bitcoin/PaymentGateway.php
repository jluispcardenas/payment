<?php
namespace Jlp\Payment\Gateways\Bitcoin;

use Jlp\Payment\IOrder;
use Jlp\Payment\IGateway;
use Input;
use View;
use Illuminate\Support\Facades\DB;
use Jlp\Payment\Gateways\Bitcoin\ElectrumHelper;


class PaymentGateway implements IGateway
{
    protected $order;
    private $service_provider = "Electrum";
    private $confs_num = 4;
    private $store_currency = "USD";
    private $exchange_rate = 0.00;
    private $bitcoin_addr_merchant = NULL;
    private $exchange_rate_type = "realtime"; // Requested vwap, realtime or bestrate
    private $generate_bitcoin_addr = NULL;
    private $reuse_expired_addresses = true;

    function __construct()
    {
        // Define user set variables
        $this->bitcoin_addr_merchant = getenv('PAYMENT_BITCOIN_WALLET');
		$this->store_currency = getenv('STORE_CURRENCY');
		$this->exchange_rate_type = getenv('EXCHANGE_RATE_TYPE');
		$this->reuse_expired_addresses = getenv('REUSE_EXPIRE_ADDRESSES');

        $this->isValidForUse();
    }

    public function isValidForUse()
    {
        $mpk = $this->getNextAvailableMpk();
        if (!$mpk) {
            throw new PaymentGatewayException("Please specify Electrum Master Public Key (MPK). To retrieve MPK: launch your electrum wallet, select: Wallet->Master Public Keys, OR: <br />Preferences->Import/Export->Master Public Key->Show)");
        }  else if (!preg_match('/^[a-f0-9]{128}$/', $mpk) && !preg_match('/^xpub[a-zA-Z0-9]{107}$/', $mpk)) {
            throw new PaymentGatewayException("Electrum Master Public Key is invalid. Must be 128 or 111 characters long, consisting of digits and letters.");
        } else if (!extension_loaded('gmp') && !extension_loaded('bcmath')) {
            throw new PaymentGatewayException("ERROR: neither 'bcmath' nor 'gmp' math extensions are loaded For Electrum wallet options to function. Contact your hosting company and ask them to enable either 'bcmath' or 'gmp' extensions.");
        }

        $this->exchange_rate = $this->getExchangeRateBitcoin($this->store_currency, 'getfirst', false);
        if (!$this->exchange_rate) {
            throw new PaymentGatewayException("ERROR: Cannot determine exchange rates. Make sure your PHP settings are configured properly and your server can (is allowed to) connect to external WEB services via PHP.");
        }

        return true;
    }

    public function getExchangeRateBitcoin($currency_code, $rate_retrieval_method = 'getfirst')
    {
        if ($currency_code == 'BTC') return "1.00"; // 1:
        $rates = array();

        // bitcoinaverage covers both - vwap and realtime
        $rates[] = $this->getExchangeRateFromBitcoinaverage($currency_code, $this->exchange_rate_type); // Requested vwap, realtime or bestrate
        if ($rates[0]) {
            // First call succeeded
            if ($exchange_rate_type == 'bestrate') $rates[] = $this->getExchangeRateFromBitpay($currency_code, $this->exchange_rate_type); // Requested bestrate
            $rates = array_filter($rates);
            if (count($rates) && $rates[0]) $exchange_rate = min($rates);
            else $exchange_rate = false;
        }  else {
            // First call failed
            if ($this->exchange_rate_type == 'vwap') $rates[] = $this->getExchangeRateFromBitcoincharts($currency_code, $this->exchange_rate_type);
            else $rates[] = $this->getExchangeRateFromBitpay($currency_code, $this->exchange_rate_type); // Requested bestrate
            $rates = array_values(array_filter($rates, 'strlen'));

            if (count($rates) && $rates[0]) $exchange_rate = min($rates);
            else $exchange_rate = false;
        }

        return $exchange_rate;
    }

    public function setOrder(IOrder $order)
    {
        $this->order = $order;

        return $this;
    }

    public function getPaymentForm($attributes = [])
    {
        $order = $this->order;

        $bitcoin_address = $this->getBitcoinAddress();
        $bitcoin_amount = $this->getBitcoinAmount();

        View::addNamespace('payment', __DIR__);
        return View::make('payment::form')->with(compact('bitcoin_address', 'bitcoin_amount'));
    }

    public function getBitcoinAddress() {
        return $this->generate_bitcoin_addr;
    }

	public function getBitcoinAmount() {
		return number_format($this->order->getAmount() / $this->exchange_rate, 8, '.', '');	
	}

    public function preparePayment()
    {
        $order_id = $this->order->getOrderId();
        $order_meta = array();
        $order_meta['bw_order_id'] = $order_id;
        $order_meta['bw_currency'] = $this->store_currency;

        //-----------------------------------
        // Save bitcoin payment info together with the order.
        //
        $order_total = $this->order->getAmount();

        $order_total_in_btc = ($order_total / $this->exchange_rate);

        $order_total_in_btc = sprintf("%.8f", $order_total_in_btc);

        $bitcoins_address = false;

        $order_info = array(
            'order_meta' => $order_meta,
            'order_id' => $order_id,
            'order_total' => $order_total_in_btc, // Order total in BTC
            'order_datetime' => date('Y-m-d H:i:s T') ,
            'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
            'requested_by_ua' => @$_SERVER['HTTP_USER_AGENT'],
            'requested_by_srv' => base64_encode(serialize($_SERVER)) ,
        );

        $ret_info_array = array();

        // Generate bitcoin address for electrum wallet provider.
        /*
        $ret_info_array = array (
        'result'                      => 'success', // OR 'error'
        'message'										 => '...',
        'host_reply_raw'              => '......',
        'generated_bitcoin_address'   => '1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2', // or false
        );
        */
        $ret_info_array = $this->getBitcoinAddressForPayment($this->getNextAvailableMpk() , $order_info);
        $this->generate_bitcoin_addr = @$ret_info_array['generated_bitcoin_address'];

        if (!$this->generate_bitcoin_addr) {
            throw new PaymentGatewayException("ERROR: cannot generate bitcoin address for the order: '" . @$ret_info_array['message'] . "'");
        }

        $this->order->setOrderMeta('bitcoin', ['bitcoin_addr' => $this->generate_bitcoin_addr, 'total_in_btc' => $order_total_in_btc]);

        return array('result' => 'success',);
    }

    function getNextAvailableMpk() {
        return $this->bitcoin_addr_merchant;
    }

    private function getBitcoinAddressForPayment($electrum_mpk, $order_info)
    {
        // status = "unused", "assigned", "used"
        $btc_addresses_table_name = 'btcaddresses';
        $origin_id = $electrum_mpk;

        $funds_received_value_expires_in_secs = 240 * 60;
        $assigned_address_expires_in_secs = 240 * 60;

        $clean_address = NULL;
        $current_time = time();

        if ($this->reuse_expired_addresses) {
            $reuse_expired_addresses_freshb_query_part = "OR (`status`='assigned'
				AND (('$current_time' - `assigned_at`) > '$assigned_address_expires_in_secs')
				AND (('$current_time' - `received_funds_checked_at`) < '$funds_received_value_expires_in_secs')
				)";
        }
        else $reuse_expired_addresses_freshb_query_part = "";

        //-------------------------------------------------------
        // Quick scan for ready-to-use address
        // NULL == not found
        // Retrieve:
        //     'unused'   - with fresh zero balances
        //     'assigned' - expired, with fresh zero balances (if 'reuse_expired_addresses' is true)
        //
        // Hence - any returned address will be clean to use.
        $query = "SELECT `btc_address` FROM `$btc_addresses_table_name`
			 WHERE `origin_id`='$origin_id'
			 AND `total_received_funds`='0'
			 AND (`status`='unused' $reuse_expired_addresses_freshb_query_part)
			 ORDER BY `index_in_wallet` ASC
			 LIMIT 1;"; // Try to use lower indexes first
        $clean_address = DB::select($query);

        if (empty($clean_address))
        {
            //-------------------------------------------------------
            // Find all unused addresses belonging to this mpk with possibly (to be verified right after) zero balances
            // Array(rows) or NULL
            // Retrieve:
            //    'unused'    - with old zero balances
            //    'unknown'   - ALL
            //    'assigned'  - expired with old zero balances (if 'reuse_expired_addresses' is true)
            //
            // Hence - any returned address with freshened balance==0 will be clean to use.
            if ($this->reuse_expired_addresses) {
                $reuse_expired_addresses_oldb_query_part = "OR (`status`='assigned'
					AND (('$current_time' - `assigned_at`) > '$assigned_address_expires_in_secs')
					AND (('$current_time' - `received_funds_checked_at`) > '$funds_received_value_expires_in_secs')
					)";
            }
            else $reuse_expired_addresses_oldb_query_part = "";

            $query = "SELECT * FROM `$btc_addresses_table_name`
				WHERE `origin_id`='$origin_id'
					AND `total_received_funds`='0'
				AND (
				   `status`='unused'
				   OR `status`='unknown'
				   $reuse_expired_addresses_oldb_query_part
				   )
				ORDER BY `index_in_wallet` ASC;"; // Try to use lower indexes first
            $addresses_to_verify_for_zero_balances_rows = DB::select($query);

            if (!is_array($addresses_to_verify_for_zero_balances_rows)) $addresses_to_verify_for_zero_balances_rows = array();

            //-------------------------------------------------------
            // Try to re-verify balances of existing addresses (with old or non-existing balances) before reverting to slow operation of generating new address.
            //
            $blockchains_api_failures = 0;
            foreach ($addresses_to_verify_for_zero_balances_rows as $address_to_verify_for_zero_balance_row) {
                // http://blockexplorer.com/q/getreceivedbyaddress/1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2
                // http://blockchain.info/q/getreceivedbyaddress/1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2 [?confirmations=6]
                //
                $address_to_verify_for_zero_balance = $address_to_verify_for_zero_balance_row->btc_address;

                $address_request_array = array();
                $address_request_array['btc_address'] = $address_to_verify_for_zero_balance;
                $address_request_array['required_confirmations'] = 0;
                $address_request_array['api_timeout'] = 30;
                $ret_info_array = $this->getReceivedbyaddress($address_request_array);

                if ($ret_info_array['balance'] === false) {
                    $blockchains_api_failures++;
                    if ($blockchains_api_failures >= 3)
                    {
                        // Allow no more than 3 contigious blockchains API failures. After which return error reply.
                        $ret_info_array = array(
                            'result' => 'error',
                            'message' => $ret_info_array['message'],
                            'host_reply_raw' => $ret_info_array['host_reply_raw'],
                            'generated_bitcoin_address' => false,
                        );
                        return $ret_info_array;
                    }
                } else {
                    if ($ret_info_array['balance'] == 0) {
                        // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
                        $clean_address = $address_to_verify_for_zero_balance;
                        break;
                    } else {
                        // Balance at this address suddenly became non-zero!
                        // It means either order was paid after expiration or "unknown" address suddenly showed up with non-zero balance or payment was sent to this address outside of this online store business.
                        // Mark it as 'revalidate' so cron job would check if that's possible delayed payment.
                        //
                        $address_meta = $this->unserializeAddressMeta(@$address_to_verify_for_zero_balance_row->address_meta);
                        if (isset($address_meta['orders'][0]))
							$new_status = 'revalidate'; // Past orders are present. There is a chance (for cron job) to match this payment to past (albeit expired) order.
                        else $new_status = 'used'; // No orders were ever placed to this address. Likely payment was sent to this address outside of this online store business.
                        
						$current_time = time();
                        $query = "UPDATE `$btc_addresses_table_name`
						 SET
							`status`='$new_status',
							`total_received_funds` = '{$ret_info_array['balance']}',
							`received_funds_checked_at`='$current_time'
						WHERE `btc_address`='$address_to_verify_for_zero_balance';";
                        DB::statement($query);
                    }
                }
            }
            //-------------------------------------------------------
            
        }

        //-------------------------------------------------------
        if (!$clean_address)
        {
            // Still could not find unused virgin address. Time to generate it from scratch.
            /*
            Returns:
            $ret_info_array = array (
            'result'                      => 'success', // 'error'
            'message'                     => '', // Failed to find/generate bitcoin address',
            'host_reply_raw'              => '', // Error. No host reply availabe.',
            'generated_bitcoin_address'   => '1FVai2j2FsFvCbgsy22ZbSMfUd3HLUHvKx', // false,
            );
            */
            $ret_addr_array = $this->generateNewBitcoinAddress($electrum_mpk);
            if ($ret_addr_array['result'] == 'success') $clean_address = $ret_addr_array['generated_bitcoin_address'];
        }

        if ($clean_address)
        {
            /*
            $order_info =
            array (
            'order_id'     => $order_id,
            'order_total'  => $order_total_in_btc,
            'order_datetime'  => date('Y-m-d H:i:s T'),
            'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
            );
            
            */
            /*
            $address_meta =
            array (
            'orders' =>
            array (
            // All orders placed on this address in reverse chronological order
            array (
            'order_id'     => $order_id,
            'order_total'  => $order_total_in_btc,
            'order_datetime'  => date('Y-m-d H:i:s T'),
            'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
            ),
            array (
            ...
            ),
            ),
            'other_meta_info' => array (...)
            );
            */

            // Prepare `address_meta` field for this clean address.
            $address_meta = DB::select("SELECT `address_meta` FROM `$btc_addresses_table_name` WHERE `btc_address`='$clean_address'");
            $address_meta = $this->unserializeAddressMeta($address_meta[0]->address_meta);

            if (!isset($address_meta['orders']) || !is_array($address_meta['orders'])) $address_meta['orders'] = array();

            array_unshift($address_meta['orders'], $order_info); // Prepend new order to array of orders
            if (count($address_meta['orders']) > 10) array_pop($address_meta['orders']); // Do not keep history of more than 10 unfullfilled orders per address.
            $address_meta_serialized = $this->serializeAddressMeta($address_meta);

            // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
            //
            $current_time = time();
            $remote_addr = $order_info['requested_by_ip'];
            $query = "UPDATE `$btc_addresses_table_name`
			 SET
				`total_received_funds` = '0',
				`received_funds_checked_at`='$current_time',
				`status`='assigned',
				`assigned_at`='$current_time',
				`last_assigned_to_ip`='$remote_addr',
				`address_meta`='$address_meta_serialized'
			WHERE `btc_address`='$clean_address';";
            $ret_code = DB::statement($query);

            $ret_info_array = array(
                'result' => 'success',
                'message' => "",
                'host_reply_raw' => "",
                'generated_bitcoin_address' => $clean_address,
            );

            return $ret_info_array;
        }
        //-------------------------------------------------------
        $ret_info_array = array(
            'result' => 'error',
            'message' => 'Failed to find/generate bitcoin address. ' . $ret_addr_array['message'],
            'host_reply_raw' => $ret_addr_array['host_reply_raw'],
            'generated_bitcoin_address' => false,
        );

        return $ret_info_array;
    }

    //===========================================================================
    /*
    Returns:
    $ret_info_array = array (
    'result'                      => 'success', // 'error'
    'message'                     => '', // Failed to find/generate bitcoin address',
    'host_reply_raw'              => '', // Error. No host reply availabe.',
    'generated_bitcoin_address'   => '1FVai2j2FsFvCbgsy22ZbSMfUd3HLUHvKx', // false,
    );
    */
    // If $bwwc_settings or $electrum_mpk are missing - the best attempt will be made to manifest them.
    // For performance reasons it is better to pass in these vars. if available.
    //
    function generateNewBitcoinAddress($electrum_mpk = false)
    {
        $btc_addresses_table_name = 'btcaddresses';

        $origin_id = $electrum_mpk;

        $funds_received_value_expires_in_secs = 240 * 60;
        $assigned_address_expires_in_secs = 240 * 60;

        $clean_address = false;

        // Find next index to generate
        $next_key_index = DB::select("SELECT MAX(`index_in_wallet`) AS `max_index_in_wallet` FROM `$btc_addresses_table_name` WHERE `origin_id`='$origin_id';");
        if (empty($next_key_index)) $next_key_index = 0; // Start generation of addresses from index #2 (skip two leading wallet's addresses)
        else $next_key_index = $next_key_index[0]->max_index_in_wallet + 1; // Continue with next index
        $total_new_keys_generated = 0;
        $blockchains_api_failures = 0;
        do
        {
            $new_btc_address = $this->generateBitcoinAddressFromMpk($electrum_mpk, $next_key_index);

            $address_request_array = array();
            $address_request_array['btc_address'] = $new_btc_address;
            $address_request_array['required_confirmations'] = 0;
            $address_request_array['api_timeout'] = 30;
            $ret_info_array = $this->getReceivedbyaddress($address_request_array);
            $total_new_keys_generated++;

            if ($ret_info_array['balance'] === false) $status = 'unknown';
            else if ($ret_info_array['balance'] == 0) $status = 'unused'; // Newly generated address with freshly checked zero balance is unused and will be assigned.
            else $status = 'used'; // Generated address that was already used to receive money.
            $funds_received = ($ret_info_array['balance'] === false) ? -1 : $ret_info_array['balance'];
            $received_funds_checked_at_time = ($ret_info_array['balance'] === false) ? 0 : time();

            // Insert newly generated address into DB
            $query = "INSERT INTO `$btc_addresses_table_name`
		  (`btc_address`, `origin_id`, `index_in_wallet`, `total_received_funds`, `received_funds_checked_at`, `status`) VALUES
		  ('$new_btc_address', '$origin_id', '$next_key_index', '$funds_received', '$received_funds_checked_at_time', '$status');";
            $ret_code = DB::statement($query);

            $next_key_index++;

            if ($ret_info_array['balance'] === false)
            {
                $blockchains_api_failures++;
                if ($blockchains_api_failures >= 3)
                {
                    // Allow no more than 3 contigious blockchains API failures. After which return error reply.
                    $ret_info_array = array(
                        'result' => 'error',
                        'message' => $ret_info_array['message'],
                        'host_reply_raw' => $ret_info_array['host_reply_raw'],
                        'generated_bitcoin_address' => false,
                    );
                    return $ret_info_array;
                }
            }
            else
            {
                if ($ret_info_array['balance'] == 0) {
                    // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
                    $clean_address = $new_btc_address;
                }
            }

            if ($clean_address) break;

            if ($total_new_keys_generated >= 20)
            {
                // Stop it after generating of 20 unproductive addresses.
                // Something is wrong. Possibly old merchant's wallet (with many used addresses) is used for new installation. - For this case 'starting_index_for_new_btc_addresses'
                //  needs to be proper set to high value.
                $ret_info_array = array(
                    'result' => 'error',
                    'message' => "Problem: Generated '$total_new_keys_generated' addresses and none were found to be unused. Possibly old merchant's wallet (with many used addresses) is used for new installation. If that is the case - 'starting_index_for_new_btc_addresses' needs to be proper set to high value",
                    'host_reply_raw' => '',
                    'generated_bitcoin_address' => false,
                );
                return $ret_info_array;
            }

        }
        while (true);

        // Here only in case of clean address.
        $ret_info_array = array(
            'result' => 'success',
            'message' => '',
            'host_reply_raw' => '',
            'generated_bitcoin_address' => $clean_address,
        );

        return $ret_info_array;
    }

    // Function makes sure that returned value is valid array
    function unserializeAddressMeta($flat_address_meta)
    {
        $unserialized = unserialize($flat_address_meta);
        if (is_array($unserialized)) return $unserialized;

        return array();
    }

    // Function makes sure that value is ready to be stored in DB
    function serializeAddressMeta($address_meta_arr)
    {
        return $this->safeStringEscape(serialize($address_meta_arr));
    }

    function generateBitcoinAddressFromMpkv1($master_public_key, $key_index)
    {
        return ElectrumHelper::mpk_to_bc_address($master_public_key, $key_index, ElectrumHelper::V1);
    }

    function generateBitcoinAddressFromMpkv2($master_public_key, $key_index, $is_for_change = false)
    {
        return ElectrumHelper::mpk_to_bc_address($master_public_key, $key_index, ElectrumHelper::V2, $is_for_change);
    }
    //===========================================================================
    //===========================================================================
    function generateBitcoinAddressFromMpk($master_public_key, $key_index, $is_for_change = false)
    {
        if (preg_match('/^[a-f0-9]{128}$/', $master_public_key)) return $this->generateBitcoinAddressFromMpkv1($master_public_key, $key_index);

        if (preg_match('/^xpub[a-zA-Z0-9]{107}$/', $master_public_key)) return $this->generateBitcoinAddressFromMpkv2($master_public_key, $key_index, $is_for_change);

        return false;
    }

    function jobWorker($hardcron = false)
    {
        // status = "unused", "assigned", "used"
        $btc_addresses_table_name = 'btcaddresses';

        $funds_received_value_expires_in_secs = 240 * 60;
        $assigned_address_expires_in_secs = 240 * 60;
        $confirmations_required = 4;

        $clean_address = NULL;
        $current_time = time();

        // Search for completed orders (addresses that received full payments for their orders) ...
        // NULL == not found
        // Retrieve:
        //     'assigned'   - unexpired, with old balances (due for revalidation. Fresh balances and still 'assigned' means no [full] payment received yet)
        //     'revalidate' - all
        //        order results by most recently assigned
        $query = "SELECT * FROM `$btc_addresses_table_name`
		  WHERE
		  (
			(`status`='assigned' AND (('$current_time' - `assigned_at`) < '$assigned_address_expires_in_secs'))
			OR
			(`status`='revalidate')
		  )
		  AND (('$current_time' - `received_funds_checked_at`) > '$funds_received_value_expires_in_secs')
		  ORDER BY `received_funds_checked_at` ASC;"; // Check the ones that haven't been checked for longest time
        $rows_for_balance_check = DB::select($query);

        if (is_array($rows_for_balance_check)) $count_rows_for_balance_check = count($rows_for_balance_check);
        else $count_rows_for_balance_check = 0;

        if (is_array($rows_for_balance_check))
        {
            $ran_cycles = 0;
            foreach ($rows_for_balance_check as $row_for_balance_check)
            {
                $ran_cycles++; // To limit number of cycles per soft cron job.
                // Prepare 'address_meta' for use.
                $address_meta = $this->unserializeAddressMeta(@$row_for_balance_check->address_meta);

                $address_request_array = array();
                $address_request_array['address_meta'] = $address_meta;

                // Retrieve current balance at address considering required confirmations number and api_timemout value.
                $address_request_array['btc_address'] = $row_for_balance_check->btc_address;
                $address_request_array['required_confirmations'] = 4;
                $address_request_array['api_timeout'] = 30;
                $balance_info_array = $this->getReceivedbyaddress($address_request_array);

                $last_order_info = @$address_request_array['address_meta']['orders'][0];
                $row_id = $row_for_balance_check->id;

                if ($balance_info_array['result'] == 'success')
                {
                    /*
                    $balance_info_array = array (
                    'result'                      => 'success',
                    'message'                     => "",
                    'host_reply_raw'              => "",
                    'balance'                     => $funds_received,
                    );
                    */

                    // Refresh 'received_funds_checked_at' field
                    $current_time = time();
					$query = "UPDATE `$btc_addresses_table_name`
					 SET
						`total_received_funds` = '{$balance_info_array['balance']}',
						`received_funds_checked_at`='$current_time'
					WHERE `id`='$row_id';";
                    $ret_code = DB::statement($query);

                    if ($balance_info_array['balance'] > 0)
                    {

                        if ($row_for_balance_check['status'] == 'revalidate')
                        {
                            // Address with suddenly appeared balance. Check if that is matching to previously-placed [likely expired] order
                            if (!$last_order_info || !@$last_order_info['order_id'] || !@$balance_info_array['balance'] || !@$last_order_info['order_total'])
                            {
                                // No proper metadata present. Mark this address as 'xused' (used by unknown entity outside of this application) and be done with it forever.
                                $query = "UPDATE `$btc_addresses_table_name`
								   SET
									  `status` = 'xused'
								  WHERE `id`='$row_id';";
                                $ret_code = DB::statement($query);
                                continue;
                            }
                            else
                            {
                                // Metadata for this address is present. Mark this address as 'assigned' and treat it like that further down...
                                $query = "UPDATE `$btc_addresses_table_name`
								   SET
									  `status` = 'assigned'
								  WHERE `id`='$row_id';";
                                $ret_code = DB::statement($query);
                            }
                        }

                    }
                    else
                    {

                    }

                    // Note: to be perfectly safe against late-paid orders, we need to:
                    //	Scan '$address_meta['orders']' for first UNPAID order that is exactly matching amount at address.
                    if ($balance_info_array['balance'] >= $last_order_info['order_total'])
                    {
                        // Process full payment event
                        /*
                        $address_meta =
                        array (
                        'orders' =>
                        array (
                        // All orders placed on this address in reverse chronological order
                        array (
                        'order_id'     => $order_id,
                        'order_total'  => $order_total_in_btc,
                        'order_datetime'  => date('Y-m-d H:i:s T'),
                        'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
                        ),
                        array (
                        ...
                        ),
                        ),
                        'other_meta_info' => array (...)
                        );
                        */

                        // Update order' meta info
                        $address_meta['orders'][0]['paid'] = true;

                        // Process and complete the order within WooCommerce (send confirmation emails, etc...)
                        IOrder::processPaymentCompleted($last_order_info['order_id'], $balance_info_array['balance']);

                        // Update address' record
                        // Update DB - mark address as 'used'.
                        //
                        $current_time = time();

                        // Note: `total_received_funds` and `received_funds_checked_at` are already updated above.
                        //
                        // ,`address_meta`='$address_meta_serialized'
                        $query = "UPDATE `$btc_addresses_table_name`
						 SET
							`status`='used'
						WHERE `id`='$row_id';";
                        $ret_code = DB::statement($query);

                        // This is not needed here. Let it process as many orders as are paid for in the same loop.
                        // Maybe to be moved there --> //..// (to avoid soft-cron checking of balance of hundreds of addresses in a same loop)
                        //
                        // 	        //	Return here to avoid overloading too many processing needs to one random visitor.
                        // 	        //	Then it means no more than one order can be processed per 2.5 minutes (or whatever soft cron schedule is).
                        // 	        //	Hard cron is immune to this limitation.
                        // 	        if (!$hardcron && $ran_cycles >= $bwwc_settings['soft_cron_max_loops_per_run'])
                        // 	        {
                        // 	        	return;
                        // 	        }
                        
                    }
                }
                else
                {

                }
                //..//
                
            }
        }

        // Process all 'revalidate' addresses here.
        // ...
        //-----------------------------------------------------
        // Pre-generate new bitcoin address for electrum wallet
        // Try to retrieve mpk from copy of settings.
        if ($hardcron)
        {
            $electrum_mpk = $this->getNextAvailableMpk();

            // Calculate number of unused addresses belonging to currently active electrum wallet
            $origin_id = $electrum_mpk;

            $current_time = time();
            $assigned_address_expires_in_secs = 240 * 60;

            if ($this->reuse_expired_addresses) $reuse_expired_addresses_query_part = "OR (`status`='assigned' AND (('$current_time' - `assigned_at`) > '$assigned_address_expires_in_secs'))";

            // Calculate total number of currently unused addresses in a system. Make sure there aren't too many.
            // NULL == not found
            // Retrieve:
            //     'unused'   - with fresh zero balances
            //     'assigned' - expired, with fresh zero balances (if 'reuse_expired_addresses' is true)
            //
            // Hence - any returned address will be clean to use.
            $query = "SELECT COUNT(*) as `total_unused_addresses` FROM `$btc_addresses_table_name`
           WHERE `origin_id`='$origin_id'
           AND `total_received_funds`='0'
           AND (`status`='unused' $reuse_expired_addresses_query_part)
           ";
            $total_unused_addresses = DB::select($query);
			if ($total_unused_addresses && count($total_unused_addresses)) {
				$total_unused_addresses = $total_unused_addresses[0]->total_unused_addresses;	
			} else {
				$total_unused_addresses = 0;
			}

            if ($total_unused_addresses < 200)
            {
                $this->generateNewBitcoinAddress($electrum_mpk);
            }

        }
        //-----------------------------------------------------
        
    }
    //===========================================================================
    

    function safeStringEscape($str = "")
    {
        $len = strlen($str);
        $escapeCount = 0;
        $targetString = '';
        for ($offset = 0;$offset < $len;$offset++)
        {
            switch ($c = $str{$offset})
            {
                case "'":
                    // Escapes this quote only if its not preceded by an unescaped backslash
                    if ($escapeCount % 2 == 0) $targetString .= "\\";
                    $escapeCount = 0;
                    $targetString .= $c;
                    break;
                case '"':
                    // Escapes this quote only if its not preceded by an unescaped backslash
                    if ($escapeCount % 2 == 0) $targetString .= "\\";
                    $escapeCount = 0;
                    $targetString .= $c;
                    break;
                case '\\':
                    $escapeCount++;
                    $targetString .= $c;
                    break;
                default:
                    $escapeCount = 0;
                    $targetString .= $c;
                }
            }
            return $targetString;
    }

    function getReceivedbyaddress($address_request_array)
    {
        // https://blockchain.bitcoinway.com/?q=getreceivedbyaddress
        //    with POST: btc_address=12fFTMkeu3mcunCtGHtWb7o5BcWA9eFx7R&required_confirmations=6&api_timeout=20
        // https://blockexplorer.com/api/addr/1KWd23GZ4BmTMo9zcsUZXpWP4M8hmxZwRU/totalReceived
        // https://blockchain.info/q/getreceivedbyaddress/1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2 [?confirmations=6]
        $btc_address = $address_request_array['btc_address'];
        $required_confirmations = $address_request_array['required_confirmations'];
        $api_timeout = $address_request_array['api_timeout'];

        $confirmations_url_part_bci = $required_confirmations ? "?confirmations=$required_confirmations" : "";

        $funds_received = false;
        // Try to get get address balance from aggregated API first to avoid excessive hits to blockchain and other services.
        $funds_received = $this->fileGetContents('https://blockchain.bitcoinway.com/?q=getreceivedbyaddress', true, $api_timeout, false, true, $address_request_array);

        if (!is_numeric($funds_received))
        {
            // Help: http://blockchain.info/q
            $funds_received = $this->fileGetContents('https://blockchain.info/q/getreceivedbyaddress/' . $btc_address . $confirmations_url_part_bci, true, $api_timeout);

            if (!is_numeric($funds_received))
            {
                $blockchain_info_failure_reply = $funds_received;

                // Help: https://blockexplorer.com/api
                // NOTE blockexplorer API no longer has 'confirmations' parameter. Hence if blockchain.info call fails - blockchain
                //      will report successful transaction immediately.
                $funds_received = $this->fileGetContents('https://blockexplorer.com/api/addr/' . $btc_address . '/totalReceived', true, $api_timeout);

                $blockexplorer_com_failure_reply = $funds_received;
            }
        }

        if (is_numeric($funds_received)) $funds_received = sprintf("%.8f", $funds_received / 100000000.0);

        if (is_numeric($funds_received))
        {
            $ret_info_array = array(
                'result' => 'success',
                'message' => "",
                'host_reply_raw' => "",
                'balance' => $funds_received,
            );
        }
        else
        {
            $ret_info_array = array(
                'result' => 'error',
                'message' => "Blockchains API failure. Erratic replies:\n" . $blockexplorer_com_failure_reply . "\n" . $blockchain_info_failure_reply,
                'host_reply_raw' => $blockexplorer_com_failure_reply . "\n" . $blockchain_info_failure_reply,
                'balance' => false,
            );
        }

        return $ret_info_array;
    }

    // $rate_type: 'vwap' | 'realtime' | 'bestrate'
    function getExchangeRateFromBitcoinaverage($currency_code, $rate_type)
    {
        $source_url = "https://apiv2.bitcoinaverage.com/indices/global/ticker/{$currency_code}"; //"https://api.bitcoinaverage.com/ticker/global/{$currency_code}/";
        $result = $this->fileGetContents($source_url, false, 10);

        $rate_obj = @json_decode(trim($result) , true);

        if (!is_array($rate_obj)) return false;

        if (@$rate_obj['24h_avg']) $rate_24h_avg = @$rate_obj['24h_avg'];
        else if (@$rate_obj['last'] && @$rate_obj['ask'] && @$rate_obj['bid']) $rate_24h_avg = ($rate_obj['last'] + $rate_obj['ask'] + $rate_obj['bid']) / 3;
        else $rate_24h_avg = @$rate_obj['last'];

        switch ($rate_type)
        {
            case 'vwap':
                return $rate_24h_avg;
            case 'realtime':
                return @$rate_obj['last'];
            case 'bestrate':
            default:
                return min($rate_24h_avg, @$rate_obj['last']);
        }
    }

    // $rate_type: 'vwap' | 'realtime' | 'bestrate'
    function getExchangeRateFromBitcoincharts($currency_code, $rate_type)
    {
        $source_url = "http://api.bitcoincharts.com/v1/weighted_prices.json";
        $result = $this->fileGetContents($source_url, false, 10);

        $rate_obj = @json_decode(trim($result) , true);

        return @$rate_obj[$currency_code]['24h'];
    }

    // $rate_type: 'vwap' | 'realtime' | 'bestrate'
    function getExchangeRateFromBitpay($currency_code, $rate_type)
    {
        $source_url = "https://bitpay.com/api/rates";
        $result = $this->fileGetContents($source_url, false, 10);

        $rate_objs = @json_decode(trim($result) , true);
        if (!is_array($rate_objs)) return false;

        foreach ($rate_objs as $rate_obj)
        {
            if (@$rate_obj['code'] == $currency_code)
            {
                return @$rate_obj['rate']; // Only realtime rate is available
                
            }
        }

        return false;
    }

    function fileGetContents($url, $return_content_on_error = false, $timeout = 60, $user_agent = false, $is_post = false, $post_data = "")
    {
        if (!function_exists('curl_init'))
        {
            if (!$is_post)
            {
                $ret_val = @file_get_contents($url);
                return $ret_val;
            }
            else
            {
                return false;
            }
        }

        $p = substr(md5(microtime()) , 24) . 'bw'; // curl post padding
        $ch = curl_init();

        if ($is_post)
        {
            $new_post_data = $post_data;
            if (is_array($post_data))
            {
                foreach ($post_data as $k => $v)
                {
                    $safetied = $v;
                    if (is_array($safetied))
                    {
                        $safetied = serialize($safetied);
                        $safetied = $p . str_replace('=', '_', base64_encode($safetied));
                        $new_post_data[$k] = $safetied;
                    }
                }
            }
        }

        {
            // To accomodate older PHP 5.0.x systems
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // return web page
            curl_setopt($ch, CURLOPT_HEADER, false); // don't return headers
            curl_setopt($ch, CURLOPT_ENCODING, ""); // handle compressed
            curl_setopt($ch, CURLOPT_USERAGENT, $user_agent ? $user_agent : urlencode("Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.12 (KHTML, like Gecko) Chrome/9.0.576.0 Safari/534.12")); // who am i
            curl_setopt($ch, CURLOPT_AUTOREFERER, true); // set referer on redirect
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout); // timeout on connect
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); // timeout on response in seconds.
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // follow redirects
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10); // stop after 10 redirects
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verifications
            if ($is_post)
            {
                curl_setopt($ch, CURLOPT_POST, true);
            }
            if ($is_post)
            {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $new_post_data);
            }
        }

        $content = curl_exec($ch);
        $err = curl_errno($ch);
        $header = curl_getinfo($ch);
        // $errmsg  = curl_error  ($ch);
        curl_close($ch);

        if (!$err && $header['http_code'] == 200) return trim($content);
        else
        {
            if ($return_content_on_error) return trim($content);
            else return false;
        }
    }
}