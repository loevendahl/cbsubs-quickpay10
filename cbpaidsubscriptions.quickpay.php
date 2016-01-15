<?php

/**

* @version $Id: cbpaidsubscriptions.quickpay.php 1581 2012-12-24 02:36:44Z beat $

* @package CBSubs (TM) Community Builder Plugin for Paid Subscriptions (TM)

* @subpackage Plugin for Paid Subscriptions

* @copyright (C) 2007-2015 and Trademark of Lightning MultiCom SA, Switzerland - www.joomlapolis.com - and its licensors, all rights reserved

* @license http://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL version 2

*/



use CBLib\Registry\ParamsInterface;

use CBLib\Xml\SimpleXMLElement;

/*ini_set('display_errors', 'on');
error_reporting(E_ALL);*/

/** Ensure this file is being included by a parent file */

if ( ! ( defined( '_VALID_CB' ) || defined( '_JEXEC' ) || defined( '_VALID_MOS' ) ) ) { die( 'Direct Access to this location is not allowed.' ); }



global $_CB_framework;



// Avoids errors in CB plugin edit:

/** @noinspection PhpIncludeInspection */

include_once( $_CB_framework->getCfg( 'absolute_path' ) . '/components/com_comprofiler/plugin/user/plug_cbpaidsubscriptions/cbpaidsubscriptions.class.php' );



// This gateway implements a payment handler using a hosted page at the PSP:

// Import class cbpaidHostedPagePayHandler that extends cbpaidPayHandler

// and implements all gateway-generic CBSubs methods.



/**

 * Payment handler class for this gateway: Handles all payment events and notifications, called by the parent class:

 *

 * OEM base

 * Please note that except the constructor and the API version this class does not implement any public methods.

 */

class cbpaidquickpayoem extends cbpaidHostedPagePayHandler

{

	/**

	 * Gateway API version used

	 * @var int

	 */

	public $gatewayApiVersion	=	"1.3.0";



	/**

	 * Constructor

	 *

	 * @param cbpaidGatewayAccount $account

	 */


	public function __construct( $account )

	{

		parent::__construct( $account );
        $this->private_key = $this->getAccountParam( 'privatekey' );
        $this->window_key = $this->getAccountParam( 'apikey' );
        $this->api_key = $this->getAccountParam( 'apiuserapikey' );
		// Set gateway URLS for $this->pspUrl() results: first 2 are the main hosted payment page posting URL, next ones are gateway-specific:
       $this->reason = '';
	    //Quickpay do not use separate API urls
		$this->formurl = "#";
       // $this->formurl = $this->getNotifyUrl( $paymentBasket );
/*		$this->_gatewayUrls	=	array(	'psp+normal'	 => $this->formurl,

										'psp+test'		 => $this->formurl,
										
										'psp+api'		 => $this->formurl );
*/
	}

private function get_quickpay_order_status($order_id) {

	$api= new QuickpayApi();
	

	$api->setOptions($this->api_key);

  try {
			if($this->getAccountParam( 'enabled' ) == 2){
    	$api->mode = 'subscriptions?order_id=';
		}else{
		$api->mode = 'payments?order_id=';
		}


    // Commit the status request, checking valid transaction id
    $st = $api->status($order_id);
	$eval = array();
	if($st[0]["id"]){

    $eval["oid"] = $st[0]["order_id"];
	$eval["qid"] = $st[0]["id"];
	}else{
	$eval["oid"] = null;
	$eval["qid"] = null;	
	}
  
  } catch (Exception $e) {
   $eval = 'QuickPay Status: ';
		  	// An error occured with the status request
          $eval .= 'Problem: ' . $e->getMessage() ;
		 
  }

    return $eval;
  } 
	protected function gatewayUrl( $case = 'single' ) {
	$url = $this->formurl;
		return $url;
	}



	/**

	 * CBSUBS HOSTED PAGE PAYMENT API METHODS:

	 */



	/**

	 * Returns single payment request parameters for gateway depending on basket (without specifying payment type)

	 *

	 * @param  cbpaidPaymentBasket  $paymentBasket   paymentBasket object

	 * @return array                                 Returns array $requestParams

	 */

	protected function getSinglePaymentRequstParams( $paymentBasket )

	{

		// build hidden form fields or redirect to gateway url parameters array:

		$requestParams						=	$this->_getBasicRequstParams( $paymentBasket );

		// sign single payment params:

		//$this->_signRequestParams( $requestParams );



		return $requestParams;

	}

	







	/**

	 * The user got redirected back from the payment service provider with a success message: Let's see how successfull it was

	 *

	 * @param  cbpaidPaymentBasket  $paymentBasket  New empty object. returning: includes the id of the payment basket of this callback (strictly verified, otherwise untouched)

	 * @param  array                $postdata       _POST data for saving edited tab content as generated with getEditTab

	 * @return string                               HTML to display if frontend, FALSE if XML error (and not yet ErrorMSG generated), or NULL if nothing to display

	 */

	protected function handleReturn( $paymentBasket, $postdata )

	{

	/*	if ( ( count( $postdata ) > 0 ) && isset( $postdata['ordernumber'] ) ) {

			// we prefer POST for sensitive data:

			$requestdata					=	$postdata;

		} else {

			// but if customer needs GET, we will work with it too (removing CMS/CB/CBSubs specific routing params):

			$requestdata					=	$this->_getGetParams();

		}
*/
             $requestdata	=	$_GET;

		// check if ordernumber exists and if not add basket id from PDT success url:

		//if ( ! isset( $requestdata['ordernumber'] ) ) {

			$requestdata['ordernumber']		=	$this->_prepareOrderNumber( $_GET['cbpbasket'], true );

		//}



		return $this->_returnParamsHandler( $paymentBasket, $requestdata, 'R' );

	}



	/**

	 * The user cancelled his payment

	 *

	 * @param  cbpaidPaymentBasket  $paymentBasket  New empty object. returning: includes the id of the payment basket of this callback (strictly verified, otherwise untouched)

	 * @param  array                $postdata       _POST data for saving edited tab content as generated with getEditTab

	 * @return string                               HTML to display, FALSE if registration cancelled and ErrorMSG generated, or NULL if nothing to display

	 */

	protected function handleCancel( $paymentBasket, $postdata )

	{

		// The user cancelled his payment (and registration):

		if ( $this->hashPdtBackCheck( $this->_getReqParam( 'pdtback' ) ) ) {

			$paymentBasketId					=	(int) $this->_getReqParam( 'basket' );



			// check if cancel was from gateway:

			if ( ! $paymentBasketId ) {

				$paymentBasketId				=	(int) $_GET['cbpbasket'];

			}



			$exists								=	$paymentBasket->load( (int) $paymentBasketId );



			if ( $exists && ( $this->_getReqParam( 'id' ) == $paymentBasket->shared_secret ) && ( $paymentBasket->payment_status != 'Completed' ) ) {

				$paymentBasket->payment_status	=	'RedisplayOriginalBasket';



				$this->_setErrorMSG( CBPTXT::T( 'Payment cancelled.' ) );

			}

		}



		return false;

	}



	/**

	 * The payment service provider server did a server-to-server notification: Verify and handle it here:

	 *

	 * @param  cbpaidPaymentBasket  $paymentBasket  New empty object. returning: includes the id of the payment basket of this callback (strictly verified, otherwise untouched)

	 * @param  array                $postdata       _POST data for saving edited tab content as generated with getEditTab

	 * @return string                              Text to return to gateway if notification, or NULL if nothing to display

	 */

	protected function handleNotification( $paymentBasket, $postdata )

	{
/*
		if ( ( count( $postdata ) > 0 ) && isset( $postdata['ordernumber'] ) ) {

			// we prefer POST for sensitive data:

			$requestdata	=	$postdata;

		} else {

			// but if gateway needs GET, we will work with it too:

			$requestdata	=	$this->_getGetParams();

		}

*/
        $requestdata	=	$_GET;
	
				// check if ordernumber exists and if not add basket id from PDT success url:

	//	if ( ! isset( $requestdata['ordernumber'] ) ) {

			$requestdata['ordernumber']		=	$this->_prepareOrderNumber( $_GET['cbpbasket'], true);

		//}
		
		
		return $this->_returnParamsHandler( $paymentBasket, $requestdata, 'I' );

	}





	/**

	 * NOT IMPLEMENTED FOR THIS GATEWAY:

	 * Cancels an existing recurring subscription

	 *

	 * @param  cbpaidPaymentBasket  $paymentBasket  paymentBasket object

	 * @param  cbpaidPaymentItem[]  $paymentItems   redirect immediately instead of returning HTML for output

	 * @return boolean|string                       TRUE if unsubscription done successfully, STRING if error

	 */

	protected function handleStopPaymentSubscription( $paymentBasket, $paymentItems )

	{

		//return parent::handleStopPaymentSubscription( $paymentBasket, $paymentItems );		//  returns not supported text


		global $_CB_framework;



		$return										=	false;



		// only for recurring subscriptions:

if ( $paymentBasket->mc_amount3 && $paymentBasket->subscr_id) {

			$subscription_id						=	$paymentBasket->subscr_id;
}else{
	
	//no subscr id
				$this->_setLogErrorMSG( 3, null, $this->getPayName() . ': unsubscribe failed from missing subscr_id in payment basket', CBPTXT::T( 'Submitted unsubscription failed on-site.' ) . ' ' . CBPTXT::T( 'Please contact site administrator to check error log.' ) );
}


			// only if an actual subscription id exists:

if ( $subscription_id ) {
	
	
/*PLEASE NOTE: subscription could be cancelled through API, but this will prevent future reactivation from CBSUBS admin.
Thus subscription will remain "active" in gateway manager although cancelled from CBSUBS. Function below ensures that customer or admin can switch the subscription status on or off as they please from CBSUBS.
	    $apiorder= new QuickpayApi();
		
	   $apiorder->setOptions($this->api_key);
    	$apiorder->mode = 'subscriptions/';

        $response = $apiorder->cancel($subscription_id);

if ( !$response) {

					$this->_setLogErrorMSG( 3, null, $this->getPayName() . ': No response. Unsubscribe API response failed', CBPTXT::T( 'Submitted unsubscription failed on-site.' ) . ' ' . CBPTXT::T( 'Please contact site administrator to check error log.' ) );

				    }
*/					
//if ( $response["id"] && ($response["id"] == $subscription_id )) {


								$ipn				=	$this->_prepareIpn( 'R', $paymentBasket->payment_status, $paymentBasket->payment_type, 'Unsubscribe', $_CB_framework->now(), 'utf-8' );

								$ipn->test_ipn		=	$paymentBasket->test_ipn;

								$ipn->raw_result	=	'SUCCESS';

								$ipn->raw_data		=	'$message_type="STOP_PAYMENT_SUBSCRIPTION"' . ";\n";
                           //     $ipn->raw_data		.=	 'Response:'."\n".json_decode($response) . ";\n";
								$ipn->bindBasket( $paymentBasket );

								$ipn->sale_id		=	$paymentBasket->id;
                                $ipn->txn_id		=	$paymentBasket->id;
                                $ipn->subscr_id		=	$paymentBasket->id;
                                $ipn->txn_type		=	'subscr_cancel';
								$ipn->first_name	=	$paymentBasket->first_name;
								$ipn->last_name	    =	$paymentBasket->last_name;
						        $ipn->payer_email    =	$paymentBasket->payer_email;

								$this->_storeIpnResult( $ipn, 'SUCCESS' );

								$this->_bindIpnToBasket( $ipn, $paymentBasket );

								$return				=	true;

							} 
							
	/*						else {
//no id						
$this->_setLogErrorMSG( 3, null, $this->getPayName() . ': unsubscribe failed with empty response (not authorized merchant or mismatching transaction IDs)', CBPTXT::T( 'Submitted unsubscription failed on-site.' ) . ' ' . CBPTXT::T( 'Please contact site administrator to check error log.' ) );


							}

	}

*/

		return $return;



	}



	/**

	 * GATEWAY-INTERNAL SPECIFIC PRIVATE METHODS:

	 */



	/**

	 * gives gateway API URL server name from gateway URL list

	 *

	 * @return string  server-name (with 'https://' )

	 */

	private function _pspApiUrl( )

	{

		return $this->gatewayUrl( 'psp+api' );

	}



	/**

	 * sign payment request $requestParams with validation code 
	 *

	 * @param  array  $requestParams, $apikey

	 */

	private function _signRequestParams( $requestParams ,$apikey)

	{

   $base = implode(" ", ksort($requestParams));
 
   return hash_hmac("sha256", $base, $apikey);


	}





	/**

	 * Compute the CBSubs payment_status based on gateway's reply in $postdata:

	 *

	 * @param  array   $postdata  raw POST data received from the payment gateway

	 * @param  string  $reason    OUT: reason_code

     * @return string             CBSubs status

	 */

	private function _paymentStatus( $status, &$reason )

	{

	//	$status			=	cbGetParam( $postdata, 'qpstat' );
/*
20000	Approved
40000	Rejected By Acquirer
40001	Request Data Error
50000	Gateway Error
50300	Communications Error (with Acquirer)
*/


		switch ( $status ) {

			case '20000':

				$this->reason	=	null;

				$status	=	'Completed';

				break;

			case '40000':

				$this->reason	=	'Rejected by acquirer';

				$status	=	'Denied';

				break;
			
			case '40001':

				$this->reason	=	'Request Data Error';

				$status	=	'Error';

				break;
				
			case '50000':

				$this->reason	=	'Gateway error';

				$status	=	'Error';

				break;
		
			case '50300':

				$this->reason	=	'Communication error';

				$status	=	'Error';

				break;
/*
			case '003':

				$reason	=	'Card expired';

				$status	=	'Denied';

				break;

			case '004':

				$reason	=	'Transition is not allowed for transaction current state';

				$status	=	'Denied';

				break;

			case '005':

				$reason	=	'Authorization is expired';

				$status	=	'Denied';

				break;

			case '006':

				$reason	=	'Error reported by acquirer';

				$status	=	'Error';

				break;

			case '007':

				$reason	=	'Error reported by QuickPay';

				$status	=	'Error';

				break;

			case '008':

				$reason	=	'Error in request data';

				$status	=	'Error';

				break;

			case '009':

				$reason	=	'Payment aborted by shopper';

				$status	=	'Denied';

				break;
			*/

		}



		return $status;

	}



	/**

	 * Compute the CBSubs payment_type based on gateway's reply $postdata:

	 *

	 * @param  array   $postdata raw POST data received from the payment gateway

	 * @return string  Human-readable string

	 */

	private function _getPaymentType( $postdata )

	{

		$type			=	strtolower( cbGetParam( $postdata, 'cardtype' ) );



		switch ( $type ) {

			case 'american-express':

			case 'american-express-dk':

				$type	=	'American Express Credit Card';

				break;

			case 'dankort':

				$type	=	'Dankort Credit Card';

				break;

			case 'danske-dk':

				$type	=	'Danske Net Bank';

				break;

			case 'diners':

			case 'diners-dk':

				$type	=	'Diners Credit Card';

				break;

			case 'edankort':

				$type	=	'eDankort Credit Card';

				break;

			case 'fbg1886':

				$type	=	'Forbrugsforeningen af 1886';

				break;

			case 'jcb':

			case '3d-jcb':

				$type	=	'JCB Credit Card';

				break;

			case '3d-maestro':

			case '3d-maestro-dk':

				$type	=	'Maestro Credit Card';

				break;

			case 'mastercard':

			case 'mastercard-dk':

			case '3d-mastercard':

			case '3d-mastercard-dk':

				$type	=	'Mastercard Credit Card';

				break;

			case 'mastercard-debet-dk':

			case '3d-mastercard-debet-dk':

				$type	=	'Mastercard Debit Card';

				break;

			case 'nordea-dk':

				$type	=	'Nordea Net Bank';

				break;

			case 'visa':

			case 'visa-dk':

			case '3d-visa':

			case '3d-visa-dk':

				$type	=	'Visa Credit Card';

				break;

			case 'visa-electron':

			case 'visa-electron-dk':

			case '3d-visa-electron':

			case '3d-visa-electron-dk':

				$type	=	'Visa Debit Card';

				break;

			case 'paypal':

				$type	=	'PayPal';

				break;

			case 'creditcard':

			case '3d-creditcard':

				$type	=	'Credit Card';

                break;

			default:

				break;

		}



		return $type;

	}

	/**

	 * Optional function: only needed for recurring payments:

	 * Returns subscription request parameters for gateway depending on basket (without specifying payment type)

	 *

	 * @param  cbpaidPaymentBasket  $paymentBasket   paymentBasket object

	 * @return array                                 Returns array $requestParams

	 */
protected function getSubscriptionRequstParams( $paymentBasket )

	{

		// mandatory parameters:
        // Payment/subscription types af handled in basic request parameters function
		$requestParams						=	$this->_getBasicRequstParams( $paymentBasket );
	    return $requestParams;
	}
	/**

	 * Popoulates basic request parameters for gateway depending on basket (without specifying payment type)

	 *

	 * @param  cbpaidPaymentBasket  $paymentBasket   paymentBasket object

	 * @return array                                 Returns array $requestParams

	 */

	private function _getBasicRequstParams( $paymentBasket )

	{
		$this->formurl ="#";
		
		// $this->formurl = $this->getNotifyUrl( $paymentBasket );

		// mandatory parameters:

		$requestParams										=	array();
      //second POST pass
	    $requestParams['cbsecuritym3']							=	$_POST["cbsecuritym3"];
        $requestParams['cbrasitway']							=	$_POST["cbrasitway"];
		$requestParams['email']							        =	$_POST["email"];
		$requestParams['username']							    =	$_POST["username"];
        $requestParams['password']							    =	$_POST["password"];
		$requestParams['cbpplanE']							    =	$_POST["cbpplanE"];
		$requestParams['cbponlyplans']							=	$_POST["cbponlyplans"];
	//
		$requestParams['version']							=	'v10';

		$requestParams['merchant_id']							=	$this->getAccountParam( 'pspid' );
		
        $requestParams['agreement_id']							=	$this->getAccountParam( 'agreementid' );

		$requestParams['language']							=	$this->getAccountParam( 'language' );

		$requestParams['order_id']						=	$this->_prepareOrderNumber( $paymentBasket->id, true );
			// check for subscription or if single payment:

		if ( $paymentBasket->period3 ) {

			$requestParams['subscription']		=	'1';

			$requestParams['amount']		=	( sprintf( '%.2f', $paymentBasket->mc_amount3 ) * 100 );

		}else{
			$requestParams['subscription']		=	'0';

			$requestParams['amount']		=	( sprintf( '%.2f', $paymentBasket->mc_gross ) * 100 );
		}


		$requestParams['currency']							=	$paymentBasket->mc_currency;

        $requestParams['autocapture']						=	'1';


		// urls for return, cancel, and IPNs:

		$requestParams['continueurl']						=	$this->getSuccessUrl( $paymentBasket );

		$requestParams['cancelurl']							=	$this->getCancelUrl( $paymentBasket );

		$requestParams['callbackurl']						=	$this->getNotifyUrl( $paymentBasket );

		// optional parameters:

		$requestParams['description']						=	"cbsubs payment ".$requestParams['order_id'];

		//$requestParams['testmode']							=	( $this->getAccountParam( 'normal_gateway' ) == '0' ? '1' : '0' );
       
		$requestParams["variables[shopsystem]"]                =   "CB subscriptions";


		// recommended anti-fraud fields:
       
		$requestParams['variables[user_id]']					=	$paymentBasket->user_id;

		$requestParams['variables[first_name]']					=	$paymentBasket->first_name;

		$requestParams['variables[last_name]']					=	$paymentBasket->last_name;



		if ( $this->getAccountParam( 'givehiddenemail' ) && ( strlen( $paymentBasket->payer_email ) <= 50 ) ) {

			$requestParams['variables[email]']					=	$paymentBasket->payer_email;

		}



		if ( $this->getAccountParam( 'givehiddenddress' ) ) {

			cbimport( 'cb.tabs' ); // needed for cbIsoUtf_substr()



			$addressFields									=	array(	'variables[address_one]' => array( $paymentBasket->address_street, 30 ),

																		'variables[postal_code]' => array( $paymentBasket->address_zip, 10 ),

																		'variables[city]' => array( $paymentBasket->address_city, 30 ),

																		'variables[country]' => array( $this->countryToLetters( $paymentBasket->address_country, 3 ), 3 )

																	);



			if ( $paymentBasket->address_state != 'other' ) {

				$addressFields['variables[state_or_province]']	=	array( substr( $paymentBasket->address_state, -2 ), 2 );

			}



			foreach ( $addressFields as $k => $value_maxlength ) {

				$adrField									=	cbIsoUtf_substr( $value_maxlength[0], 0, $value_maxlength[1] );



				if ( $adrField ) {

					$requestParams[$k]						=	$adrField;

				}

			}

		}



		if ( $this->getAccountParam( 'givehiddentelno' ) && ( strlen( $paymentBasket->contact_phone ) <= 50 ) ) {

			$requestParams['variables[phone]']					=	$paymentBasket->contact_phone;

		}
 /* not needed. Using v10 payment link instead
ksort($requestParams);
         $requestParams["checksum"] = $this->_signRequestParams($requestParams, $this->window_key);
*/	

	if($_POST['callquickpay'] == "go") {
	    $apiorder= new QuickpayApi();
	    $apiorder->setOptions($this->api_key);
	  	//been here before?
	    $exists = $this->get_quickpay_order_status($requestParams['order_id']);
    $qid = $exists["qid"];

	  	if($paymentBasket->period3){
    	$apiorder->mode = 'subscriptions/';
		}
	  
	  if($exists["qid"] == null){

      //create new quickpay payment or subscription order	
      $storder = $apiorder->createorder($requestParams['order_id'], $requestParams['currency'],$requestParams);
      $qid = $storder["id"];
	  
      }else{
       $qid = $exists["qid"];
       }

		//create or update payment link	
		$storder = $apiorder->link($qid, $requestParams);	
		header("location: ".$storder['url']);
    
}
        $requestParams["callquickpay"] = 'go';
		
		return $requestParams;

	}





	/**

	 * The user got redirected back from the payment service provider with a success message: let's see how successfull it was

	 *

	 * @param  cbpaidPaymentBasket  $paymentBasket       New empty object. returning: includes the id of the payment basket of this callback (strictly verified, otherwise untouched)

	 * @param  array                $requestdata         Data returned by gateway

	 * @param  string               $type                Type of return ('R' for PDT, 'I' for INS, 'A' for Autorecurring payment (Vault) )

     * @param  array                $additionalLogData   Additional strings to log with IPN

     * @return string                                    HTML to display if frontend, text to return to gateway if notification, FALSE if registration cancelled and ErrorMSG generated, or NULL if nothing to display

	 */

	private function _returnParamsHandler( $paymentBasket, $requestdata, $type, $additionalLogData = null )

	{
			global $_CB_framework, $_GET, $_POST;
    
	$oid = $requestdata["ordernumber"];
	
		$qp = new QuickpayApi;

		$qp->setOptions( $this->api_key);
		if($this->getAccountParam( 'enabled' ) == 2){
    	$qp->mode = 'subscriptions?order_id=';
		}else{
		$qp->mode = 'payments?order_id=';
		}
    // Commit the status request, checking valid transaction id
     $str = $qp->status($oid);
	 $str["operations"][0] = array_reverse($str["operations"][0]);
 
 $qp_status = $str[0]["operations"][0]["qp_status_code"];
 $qp_type = strtolower($str[0]["type"]);
 $qp_status_msg = $str[0]["operations"][0]["qp_status_msg"];
 $qp_vars = $str[0]["variables"];
 $qp_id = $str[0]["id"];
 $qp_order_id = $str[0]["order_id"];
 $qp_aq_status_code = $str[0]["aq_status_code"];
 $qp_aq_status_msg = $str[0]["aq_status_msg"];
  $qp_cardtype = $str[0]["metadata"]["brand"];
  $qp_cardnumber = "xxxx-xxxxxx-".$str[0]["metadata"]["last4"];
  $qp_amount = $str[0]["operations"][0]["amount"];
  $qp_currency = $str[0]["currency"];
  $qp_pending = ($str[0]["pending"] == "true" ? " - pending ": "");
  $qp_expire = $str[0]["metadata"]["exp_month"]."-".$str[0]["metadata"]["exp_year"];
                                                                     



		$ret													=	null;

		$paymentBasketId										=	$requestdata["cbpbasket"];



		if ( $paymentBasketId ) {

			$exists												=	$paymentBasket->load( (int) $paymentBasketId );



			if ( $exists && ( ( $requestdata["cbpid"] == $paymentBasket->shared_secret ) && ( ! ( ( ( $type == 'R' ) || ( $type == 'I' ) ) && ( $paymentBasket->payment_status == 'Completed' ) ) ) ) ) {

				// PDT doesn't return transacton information; lets request for it:

/*				if ( $type == 'R' ) {
					$requestdata = $str;

					$formvars									=	array();

					$formvars['protocol']						=	'3';

					$formvars['msgtype']						=	'status';

					$formvars['merchant']						=	$this->getAccountParam( 'pspid' );

					$formvars['ordernumber']					=	$this->_prepareOrderNumber( $paymentBasket->id, true );

					$formvars['splitpayment']					=	'0';



					$this->_signRequestParams( $formvars );



					$response									=	null;

					$status										=	null;

					$error										=	$this->_httpsRequest( $this->_pspApiUrl(), $formvars, 30, $response, $status, 'post', 'normal', '*/
					/*', true, 443, '', '', true, null );
*/

/*
					if ( ( ! $error ) && ( $status == 200 ) && $response ) {

						$xml_response							=	$this->xmlTagValuesToArray( new SimpleXMLElement( $response, LIBXML_NONET | ( defined('LIBXML_COMPACT') ? LIBXML_COMPACT : 0 ) ) );



						if ( $xml_response ) {

							$requestdata						=	$xml_response;

						}

					}

			}

*/

				// Log the return record:

				$log_type										=	$type;

				$reason											=	null;

				$paymentStatus									=	$this->_paymentStatus( $qp_status, $this->reason );

				$paymentType									=	$qp_cardtype;

				$paymentTime									=	$_CB_framework->now();



				if ( $paymentStatus == 'Error' ) {

					$errorTypes									=	array( 'I' => 'D', 'R' => 'E' );



					if ( isset( $errorTypes[$type] ) ) {

						$log_type								=	$errorTypes[$type];

					}

				}



				$ipn											=	$this->_prepareIpn( $log_type, $paymentStatus, $paymentType, $this->reason, $paymentTime, 'utf-8' );



				if ( $qp_type == 'refund' ) {

					// in case of refund we need to log the payment as it has same TnxId as first payment: so we need payment_date for discrimination:

					$ipn->payment_date							=	gmdate( 'H:i:s M d, Y T', $paymentTime ); // paypal-style

				}



				$ipn->test_ipn									=	0;

				$ipn->raw_data									=	'$message_type="' . ( $type == 'R' ? 'RETURN_TO_SITE' : ( $type == 'I' ? 'NOTIFICATION' : 'UNKNOWN' ) ) . '";' . "\n";



				if ( $additionalLogData ) {

					foreach ( $additionalLogData as $k => $v ) {

						$ipn->raw_data							.=	'$' . $k . '="' . var_export( $v, true ) . '";' . "\n";

					}

				}



				$ipn->raw_data									.=	/* cbGetParam() not needed: we want raw info */ '$requestdata=' . var_export( $requestdata, true ) . ";\n"

																.	/* cbGetParam() not needed: we want raw info */ '$_GET=' . var_export( $_GET, true ) . ";\n"

																.	/* cbGetParam() not needed: we want raw info */ '$_POST=' . var_export( $_POST, true ) . ";\n";



				if ( $paymentStatus == 'Error' ) {

					$paymentBasket->reason_code					=	$this->reason;



					$this->_storeIpnResult( $ipn, 'ERROR:' . $this->reason );

					$this->_setLogErrorMSG( 4, $ipn, $this->getPayName() . ': ' . $this->reason, CBPTXT::T( 'Sorry, the payment server replied with an error.' ) . ' ' . CBPTXT::T( 'Please contact site administrator to check payment status and error log.' ) );



					$ret										=	false;

				} else {

					$ipn->bindBasket( $paymentBasket );



					$ipn->sale_id								=	$paymentBasketId;



					$insToIpn									=	 array(	'txn_id' => $qp_id,

																			'mc_currency' => $qp_currency,

																			'receiver_email' => $qp_vars["merchant_email"],

																			'first_name' => $qp_vars['first_name'],

																			'last_name' => $qp_vars['last_name'],

																			'address_street' => $qp_vars['address_one'],

																			'address_zip' => $qp_vars['postal_code'],

																			'address_city' => $qp_vars['city'],

																			'address_country' => $qp_vars['country'],

																			'address_state' => $qp_vars['state_or_province'],

																			'contact_phone' => $qp_vars['phone'],

																			'payer_email' => $qp_vars['email']

																		);




					foreach ( $insToIpn as $k => $v ) {

						$ipn->$k								=	$v;

					}



					$ipn->mc_gross								=	sprintf( '%.2f', ( $qp_amount / 100 ) );

					$ipn->user_id								=	(int) $paymentBasket->user_id;



					// check what type of purchase this is:

					$recurring		=	( in_array($qp_type, array( 'subscription', 'recurring' )) ? true : false );
                    //subscription handling


	
					// handle recurring subscriptions properly or default to single payment:

					if ( $recurring ) {
					$qp->mode = "subscriptions/";
	                $addlink = $qp_id."/recurring/";
	                $process_parameters["amount"] = $qp_amount;
	                $process_parameters["order_id"] = $qp_order_id."-".$qp_id;
	                $process_parameters["auto_capture"] = TRUE;	
                    $storder = $qp->createorder($qp_order_id, $qp_currency_code, $process_parameters, $addlink);

						if ( ( $paymentStatus == 'Completed' ) && ( ! $paymentBasket->subscr_id ) ) {

							$ipn->txn_type						=	'subscr_signup';

							$ipn->subscr_id						=	$qp_id;

							$ipn->subscr_date					=	$ipn->payment_date;

						} elseif ( $paymentStatus == 'Denied' ) {

							if ( ( $paymentBasket->reattempts_tried + 1 ) <= cbpaidScheduler::getInstance( $this )->retries ) {

								$ipn->txn_type					=	'subscr_failed';

							} else {

								$ipn->txn_type					=	'subscr_cancel';

							}

						} elseif ( in_array( $paymentStatus, array( 'Completed', 'Processed', 'Pending' ) ) ) {

							$ipn->txn_type						=	'subscr_payment';

						}

					} else {

						$ipn->txn_type							=	'web_accept';

					}



					// validate payment from PDT or IPN

					$apiReplyRaw								=	null;

					$apiReplyArray								=	null;

					if ( $qp_status == 20000 ) {

						$ipn->raw_data							.=	/* cbGetParam() not needed: we want raw info */ '$apiReplyRaw=\'' . str_replace( array( '\\', '\'' ), array( '\\\\', '\\\'' ), $apiReplyRaw ) . "';\n"

																.	/* cbGetParam() not needed: we want raw info */ '$apiReplyFormattedArray=' . var_export( $apiReplyArray, true ) . ";\n"

																;

						if ( ( $paymentBasketId == $requestdata["cbpbasket"] ) && ( ( sprintf( '%.2f', $paymentBasket->mc_gross ) == $ipn->mc_gross ) || ( $ipn->payment_status == 'Refunded' ) ) && ( $paymentBasket->mc_currency == $ipn->mc_currency ) ) {

							if ( in_array( $ipn->payment_status, array( 'Completed', 'Processed', 'Pending', 'Refunded', 'Denied' ) ) ) {

								$this->_storeIpnResult( $ipn, 'SUCCESS' );

								$this->_bindIpnToBasket( $ipn, $paymentBasket );



								// add the gateway to the basket:

								$paymentBasket->payment_method	=	$this->getPayName();

								$paymentBasket->gateway_account	=	$this->getAccountParam( 'id' );



								// 0: not auto-recurring, 1: auto-recurring without payment processor notifications, 2: auto-renewing with processor notifications updating $expiry_date:

								$autorecurring_type				=	( in_array( $ipn->txn_type, array( 'subscr_payment', 'subscr_signup', 'subscr_modify', 'subscr_eot', 'subscr_cancel', 'subscr_failed' ) ) ? 2 : 0 );



								// 0: not auto-renewing (manual renewals), 1: asked for by user, 2: mandatory by configuration:

								$autorenew_type					=	( $autorecurring_type ? ( ( $this->getAccountParam( 'enabled', 0 ) == 3 ) && ( $paymentBasket->isAnyAutoRecurring() == 2 ) ? 1 : 2 ) : 0 );



								if ( $recurring ) {

									$paymentBasket->reattempt	=	1; // we want to reattempt auto-recurring payment in case of failure

								}



								$this->updatePaymentStatus( $paymentBasket, $ipn->txn_type, $ipn->payment_status, $ipn, 1, $autorecurring_type, $autorenew_type, false );



								if ( in_array( $ipn->payment_status, array( 'Completed', 'Processed', 'Pending' ) ) ) {

									$ret						=	true;

								}

							} else {

								$this->_storeIpnResult( $ipn, 'FAILED' );



								$paymentBasket->payment_status	=	$ipn->payment_status;



								$this->_setErrorMSG( '<div class="message">' . $this->getTxtNextStep( $paymentBasket ) . '</div>' );



								$paymentBasket->payment_status	=	'RedisplayOriginalBasket';

								$ret							=	false;

							}

						} else {

							$this->_storeIpnResult( $ipn, 'MISMATCH' );

							$this->_setLogErrorMSG( 3, $ipn, $this->getPayName() . ': amount or currency missmatch', CBPTXT::T( 'Sorry, the payment does not match the basket.' ) . ' ' . CBPTXT::T( 'Please contact site administrator to check error log.' ) );



							$ret								=	false;

						}

					} else {

						$this->_storeIpnResult( $ipn, 'SIGNERROR' );

						$this->_setLogErrorMSG( 3, $ipn, $this->getPayName() . ': Transaction does not match with gateway. Please check API Key setting', CBPTXT::T( 'The API Key is incorrect.' ) . ' ' . CBPTXT::T( 'Please contact site administrator to check error log.' ) );



						$ret									=	false;

					}

				}

			}

		} else {

			$this->_setLogErrorMSG( 3, null, $this->getPayName() . ': ordernumber is missing in the return URL: ' . var_export( $_GET, true ), CBPTXT::T( 'Please contact site administrator to check error log.' ) );

		}



		return  $ret;

	}



	/**

	 * Output properly formatted basket id from ordernumber

	 *

	 * @param  int      $ordernumber

	 * @param  boolean  $append

	 * @return string

	 */

	private function _prepareOrderNumber( $ordernumber, $append = false )

	{

		if ( $append ) {

			return preg_replace( '/[^-a-zA-Z0-9]/', '', $this->getAccountParam( 'pspprefix' ) . $ordernumber );

		} else {

			return str_replace( $this->getAccountParam( 'pspprefix' ), '', $ordernumber );

		}

	}



	/**

	 * PRIVATE BACKEND FUNCTION USED BY CLASS cbpaidGatewayAccountccbilloem below

	 */



	/**

	 * USED by XML interface ONLY !!! Renders URL to set in the QuickPay interface for notifications:

	 * Called by cbpaidGatewayAccountquickpayoem::renderNotifyUrl() just below in next class

	 *

	 * @param  string  $gatewayId

	 * @return string  HTML to display

	 */

	public function renderNotifyUrl( /** @noinspection PhpUnusedParameterInspection */ $gatewayId )

	{

		//FIXME : in such cases, the notification URL should not contain the gateway ID and be independant of the gateway ID but only of the gateway TYPE: That way multiple gateways for same PSP account can be setup (e.g. for conditions on promos)

		return $this->getNotifyUrl( null );

	}

}



/**

 * Payment account class for this gateway: Stores the settings for that gateway instance, and is used when editing and storing gateway parameters in the backend.

 *

 * OEM base

 * No methods need to be implemented or overriden in this class, except to implement the private-type params used specifically for this gateway:

 */

class cbpaidGatewayAccountquickpayoem extends cbpaidGatewayAccounthostedpage		//  extends cbpaidGatewayAccount

{

	/**

	 * USED by XML interface ONLY !!! Renders URL for notifications

	 *

	 * @param  string           $gatewayId  Id of gateway

	 * @param  ParamsInterface  $params     Params of gateway

	 * @return string                       HTML to display

	 */

	public function renderNotifyUrl( $gatewayId, /** @noinspection PhpUnusedParameterInspection */ $params )

	{

		$payClass	=	$this->getPayMean();

		return str_replace( 'http://', 'https://', $payClass->renderNotifyUrl( $gatewayId ) );

	}



	/**

	 * USED by XML interface ONLY !!! Renders URL for site returns

	 *

     * @param  string           $gatewayId  Id of gateway

     * @param  ParamsInterface  $params     Params of gateway

     * @return string                       HTML to display

     */

	public function renderSiteUrl( $gatewayId, $params )

	{

		global $_CB_framework;

		return $_CB_framework->getCfg( 'live_site' );

	}



    /**

     * Gets payment mean handler : Overridde to phpdocument return of correct class

     *

     * @param  string             $methodCheck

     * @return cbpaidquickpayoem

     */

    public function getPayMean( $methodCheck = null )

	{

        return parent::getPayMean( $methodCheck );

    }


}



/**

 * Payment handler class for this gateway: Handles all payment events and notifications, called by the parent class:

 *

 * Gateway-specific

 * Please note that except the constructor and the API version this class does not implement any public methods.

 */

class cbpaidquickpay extends cbpaidquickpayoem

{

}



/**

 * Payment account class for this gateway: Stores the settings for that gateway instance, and is used when editing and storing gateway parameters in the backend.

 *

 * Gateway-specific

 * No methods need to be implemented or overriden in this class, except to implement the private-type params used specifically for this gateway:

 */

class cbpaidGatewayAccountquickpay extends cbpaidGatewayAccountquickpayoem

{

}

/**
 * Quickpay v10+ php library
 * 
 * This class implements the Quickpay connector interface, by using curl.
 * If curl is not present in the environment an execption is thrown on instantiation.
 */
class QPConnectorCurl implements QPConnectorInterface {

    protected $connTimeout = 10;
    protected $apiUrl = "https://api.quickpay.net";
    protected $apiVersion = 'v10';
    protected $apiKey = "";
    protected $format = "application/json";    

    public function __constructor() {
        if (!function_exists('curl_init')){
            throw Exception('CURL is not installed, please install curl or change connection method');
        }     
    }

    public function setOptions($apiKey, $connTimeout=10, $apiVersion="v10") {
       $this->connTimeout = $connTimeout;
       $this->apiKey = $apiKey;
       $this->apiVersion = $apiVersion;
    }
    
   

 
    public function request($resource, $postdata=null, $sendmode='GET-POST') {
        $curl =  curl_init();
        $url = $this->apiUrl . "/" . $resource;
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->connTimeout);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER,true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Authorization: Basic ' . base64_encode(":" . $this->apiKey),
	    'Accept-Version: ' . $this->apiVersion,
            'Accept: ' . $this->format
        ));
        if (!is_null($postdata)) {
			if($sendmode=='GET-POST'){
	  curl_setopt($curl, CURLOPT_POST, 1);
			}else{
	  curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT"); 		
			}
	  curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postdata));		
	}

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);         
        curl_close($curl);

	if ($httpCode!=200 && $httpCode!=202) {
	 // throw new Exception($response, $httpCode);	
	}

        return $response;
    }

   
}
/**
 * Quickpay v10+ php library
 * 
 * Singleton for easy retrieval of the Quickpay connector implementation.
 */

class QPConnectorFactory {

    public static function getConnector() {
        static $inst = null;
        if ($inst === null) {
            $inst = new QPConnectorCurl();
        }
        return $inst;
    }

    private function __construct() {
    }
}

/**
 * Quickpay v10+ php library
 * 
 * This interface must be implemented by any Quickpay connectors.
 */

interface QPConnectorInterface {
    
    public function request($data);
    
}

class QuickpayApi {

  public $mode = "payments/";
	/**
	* Set the options for this object
	* apikey is found in https://manage.quickpay.net
	*/
	function setOptions($apiKey, $connTimeout=10, $apiVersion="v10") {
		QPConnectorFactory::getConnector()->setOptions($apiKey, $connTimeout, $apiVersion);	
	}

	/**
	* Get a list of payments.
	*/
	function getPayments() {
		$result = QPConnectorFactory::getConnector()->request($this->mode);	
		return json_decode($result, true);	
	}	

        /**
        * Get a specific payment.
        * The errorcode 404 is set in the thrown exception if the order is not found
        */
    function status($id) {
		$result = QPConnectorFactory::getConnector()->request($this->mode.$id);		

		return json_decode($result, true);			
	}
	
    function link($id,$postArray) {
		$result = QPConnectorFactory::getConnector()->request($this->mode.$id."/link?currency=".$postArray["currency"]."&amount=".$postArray["amount"], $postArray,'PUT');	
		
			
		return json_decode($result, true);			
	}
	/**
	* Renew a payment
	*/
        function renew($id) {
                $postArray = array();
                $postArray['id'] = $id;
		$result = QPConnectorFactory::getConnector()->request($this->mode . $id . '/renew', $postArray);	
		return json_decode($result, true);			
	}
	
	/**
	* Capture a payment
	*/
        function capture($id, $amount, $extras=null) {
                $postArray = array();
                $postArray['id'] = $id;
                $postArray['amount'] = $amount;
                if (!is_null($extras)) {
		  $postArray['extras'] = $extras;
		}
		$result = QPConnectorFactory::getConnector()->request($this->mode . $id . '/capture', $postArray);	
		return json_decode($result, true);			
	}

	/**
	* Refund a payment
	*/
        function refund($id, $amount, $extras=null) {
                $postArray = array();
                $postArray['id'] = $id;
                $postArray['amount'] = $amount;
                if (!is_null($extras)) {
		  $postArray['extras'] = $extras;
		}
		$result = QPConnectorFactory::getConnector()->request($this->mode . $id . '/refund', $postArray);	
		return json_decode($result, true);			
	}


	/**
	* Cancel a payment
	*/
        function cancel($id) {
                $postArray = array();
                $postArray['id'] = $id;
		$result = QPConnectorFactory::getConnector()->request($this->mode . $id . '/cancel', $postArray);	
		
		return json_decode($result, true);			
	}
 
 function createorder($order_id, $currency, $postArray,$addlink='') {
             /*     $postArray = array();
                $postArray['order_id'] = $order_id;
                $postArray['currency'] = $currency;
				$postArray['description'] = $order_id; */
		$result = QPConnectorFactory::getConnector()->request($this->mode.$addlink.'?order_id='.$order_id.'&currency='.$currency, $postArray);	

		return json_decode($result, true);			
	}
	   

public function init() {
        //check for curl 
        if(!extension_loaded('curl')) {
         
            return false;
        }
	

        return true;
    }
}