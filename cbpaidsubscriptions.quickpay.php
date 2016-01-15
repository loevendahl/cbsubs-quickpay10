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

		// Set gateway URLS for $this->pspUrl() results: first 2 are the main hosted payment page posting URL, next ones are gateway-specific:
		$this->_gatewayUrls	=	array(	'psp+normal'	 => $this->getAccountParam( 'psp_normal_url' ),
										'psp+test'		 => $this->getAccountParam( 'psp_test_url' ),
										'psp+api+normal' => $this->getAccountParam( 'psp_api_normal_url' ),
										'psp+api+test'	 => $this->getAccountParam( 'psp_api_test_url' ) );
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
		$this->_signRequestParams( $requestParams );

		return $requestParams;
	}
	
	/**
	 * NOT IMPLEMENTED FOR THIS GATEWAY:
	 * Optional function: only needed for recurring payments:
	 * Returns subscription request parameters for gateway depending on basket (without specifying payment type)
	 *
	 * @param  cbpaidPaymentBasket  $paymentBasket   paymentBasket object
	 * @return array                                 Returns array $requestParams
	 */
	protected function getSubscriptionRequstParams( $paymentBasket )
	{
		// mandatory parameters:
		$requestParams						=	$this->_getBasicRequstParams( $paymentBasket );

		// check for subscription or if single payment:
		if ( $paymentBasket->period3 ) {
			$requestParams['msgtype']		=	'subscribe';
			$requestParams['amount']		=	( sprintf( '%.2f', $paymentBasket->mc_amount3 ) * 100 );
		}
		// sign reocurring payment params:
		$this->_signRequestParams( $requestParams );

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
		if ( ( count( $postdata ) > 0 ) && isset( $postdata['ordernumber'] ) ) {
			// we prefer POST for sensitive data:
			$requestdata					=	$postdata;
		} else {
			// but if customer needs GET, we will work with it too (removing CMS/CB/CBSubs specific routing params):
			$requestdata					=	$this->_getGetParams();
		}

		// check if ordernumber exists and if not add basket id from PDT success url:
		if ( ! isset( $requestdata['ordernumber'] ) ) {
			$requestdata['ordernumber']		=	$this->_prepareOrderNumber( cbGetParam( $_GET, 'cbpbasket', null ), true );
		}

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
				$paymentBasketId				=	(int) $this->_prepareOrderNumber( cbGetParam( $requestdata, 'ordernumber', null ) );
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
		if ( ( count( $postdata ) > 0 ) && isset( $postdata['ordernumber'] ) ) {
			// we prefer POST for sensitive data:
			$requestdata	=	$postdata;
		} else {
			// but if gateway needs GET, we will work with it too:
			$requestdata	=	$this->_getGetParams();
		}

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
		return parent::handleStopPaymentSubscription( $paymentBasket, $paymentItems );		//  returns not supported text

        // The implementation below is work in progress, as it was not required up to now. Tested contributions welcome :-)     //TODO
/*
		global $_CB_framework;

		$return										=	false;

		// only for recurring subscriptions:
		if ( $paymentBasket->mc_amount3 ) {
			$subscription_id						=	$paymentBasket->subscr_id;

			// only if an actual subscription id exists:
			if ( $subscription_id ) {
				// mandatory parameters:
				$requestParams						=	array();
				$requestParams['protocol']			=	'3';
				$requestParams['msgtype']			=	'cancel';
				$requestParams['merchant']			=	$this->getAccountParam( 'pspid' );
				$requestParams['transaction']		=	$subscription_id;

				// sign cancel subscription params:
				$this->_signRequestParams( $requestParams );

				$response							=	null;
				$status								=	null;
				$error								=	$this->_httpsRequest( $this->_pspApiUrl(), $requestParams, 45, $response, $status, 'post', 'xml' );

				if ( $error || ( $status != 200 ) || ( ! $response ) ) {
					$this->_setLogErrorMSG( 3, null, $this->getPayName() . ': unsubscribe API response failed', CBPTXT::T( 'Submitted unsubscription failed on-site.' ) . ' ' . CBPTXT::T( 'Please contact site administrator to check error log.' ) );
				} else {
					$requestdata					=	$this->xmlTagValuesToArray( new SimpleXMLElement( $response, LIBXML_NONET | ( defined('LIBXML_COMPACT') ? LIBXML_COMPACT : 0 ) ) );

					if ( $requestdata ) {
						$apiReplyRaw				=	null;
						$apiReplyArray				=	null;
						if ( $this->_pspVerifySignature( $requestdata, $apiReplyRaw, $apiReplyArray ) ) {
							if ( (int) cbGetParam( $requestdata, 'state' ) == 5 ) {
								$ipn				=	$this->_prepareIpn( 'R', $paymentBasket->payment_status, $paymentBasket->payment_type, 'Unsubscribe', $_CB_framework->now(), 'utf-8' );
								$ipn->test_ipn		=	$paymentBasket->test_ipn;
								$ipn->raw_result	=	'SUCCESS';
								$ipn->raw_data		=	'$message_type="STOP_PAYMENT_SUBSCRIPTION"' . ";\n"
													.	/* cbGetParam() not needed: we want raw info * '$requestdata=' . var_export( $requestdata, true ) . ";\n"
													.	/* cbGetParam() not needed: we want raw info * '$_GET=' . var_export( $_GET, true ) . ";\n"
													.	/* cbGetParam() not needed: we want raw info * '$_POST=' . var_export( $_POST, true ) . ";\n"
													.	/* cbGetParam() not needed: we want raw info * '$apiReplyRaw=\'' . str_replace( array( '\\', '\'' ), array( '\\\\', '\\\'' ), $apiReplyRaw ) . "';\n"
													.	/* cbGetParam() not needed: we want raw info * '$apiReplyFormattedArray=' . var_export( $apiReplyArray, true ) . ";\n"
													;
								$ipn->bindBasket( $paymentBasket );

								$ipn->sale_id		=	$paymentBasket->id;

								$insToIpn			=	array(	'txn_id' => 'transaction',
																'subscr_id' => 'transaction'
															);

								foreach ( $insToIpn as $k => $v ) {
									$ipn->$k		=	cbGetParam( $requestdata, $v );
								}

								$ipn->txn_type		=	'subscr_cancel';

								$this->_storeIpnResult( $ipn, 'SUCCESS' );
								$this->_bindIpnToBasket( $ipn, $paymentBasket );

								$return				=	true;
							} else {
								$this->_setLogErrorMSG( 3, null, $this->getPayName() . ': ' . cbGetParam( $requestdata, 'qpstatmsg', null ), CBPTXT::T( 'Sorry, the payment server replied with an error.' ) . ' ' . CBPTXT::T( 'Please contact site administrator to check payment status and error log.' ) );
							}
						} else {
							$this->_setLogErrorMSG( 3, null, $this->getPayName() . ': md5check or transaction does not match with gateway. Please check Secret Key setting', CBPTXT::T( 'The Secret Key signature is incorrect.' ) . ' ' . CBPTXT::T( 'Please contact site administrator to check error log.' ) );
						}
					} else {
						$this->_setLogErrorMSG( 3, null, $this->getPayName() . ': unsubscribe failed with empty response', CBPTXT::T( 'Submitted unsubscription failed on-site.' ) . ' ' . CBPTXT::T( 'Please contact site administrator to check error log.' ) );
					}
				}
			} else {
				$this->_setLogErrorMSG( 3, null, $this->getPayName() . ': unsubscribe failed from missing subscr_id in payment basket', CBPTXT::T( 'Submitted unsubscription failed on-site.' ) . ' ' . CBPTXT::T( 'Please contact site administrator to check error log.' ) );
			}
		}

		return $return;
*/
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
	 * sign payment request $requestParams with validation code added to $requestParams array
	 *
	 * @param  array  $requestParams
	 */
	private function _signRequestParams( &$requestParams )
	{
		// List of parameters to concatenate if not empty:
		$listOfParams				=	array(
									'PROTOCOL',			// api version (4)
									'MSGTYPE',			// subscription type (authorize or subscribe)
									'MERCHANT',			// merchant id (pspid)
									'LANGUAGE',			// 2-letter language code (da)
									'ORDERNUMBER',		// item code (basket id)
									'AMOUNT',			// purchase price (10.01, etc..)
									'CURRENCY',			// currency code
									'CONTINUEURL',		// return url (success)
									'CANCELURL',		// return url (cancel)
									'CALLBACKURL',		// callback url (IPN)
									'AUTOCAPTURE',		// auto capture of transaction
									'CARDTYPELOCK',		// card type lock
									'DESCRIPTION',		// item description
									'TESTMODE',			// transaction test or not (1 or 0)
									'SPLITPAYMENT',		// transaction split payment
									'$pspsecret'		// Concatenate security code generated at gateway: equivalent to: $this->getAccountParam( 'pspsecret' )
									);
		// Concatenate them using this payments concatenation function with $caseInsensitiveKeys = true:
		$string						=	$this->_concatVars( $requestParams, $listOfParams, null, '', '', false, false, true, false );
		
		// add validation code doing md5 of the string without uppercasing:
		$requestParams['md5check']	=	$this->_hashString( $string, 'md5', false );
	}

	/**
	 * Validate a reply in $requestParams using md5check value, and then also through the API if it is available
	 *
	 * @param  array   $requestParams
	 * @param  string  $apiReplyRaw    Returned in case of valid and verified through API too: API reply, raw format
	 * @param  string  $apiReplyArray  Returned in case of valid and verified through API too: API reply, formated
	 * @param  boolean $rawParams      $requestParams are raw $_POST or $_GET input and should be sanitized and unescaped if needed
	 * @return boolean                 TRUE: valid, FALSE: invalid
	 */
	private function _pspVerifySignature( $requestParams, &$apiReplyRaw, &$apiReplyArray, $rawParams = false )
	{
		// List of parameters to concatenate if not empty:
		$listOfParams				=	array(
									'MSGTYPE',			// subscription type (authorize or subscribe)
									'ORDERNUMBER',		// item code (basket id)
									'AMOUNT',			// purchase price (10.01, etc..)
									'CURRENCY',			// currency code
									'TIME',				// purchase time
									'STATE',			// transaction state
									'QPSTAT',			// transaction return code
									'QPSTATMSG',		// transaction return message
									'CHSTAT',			// clearing house return code
									'CHSTATMSG',		// clearing house return message
									'MERCHANT',			// merchant name
									'MERCHANTEMAIL',	// merchant email
									'TRANSACTION',		// transaction id
									'CARDTYPE',			// purchase card type
									'CARDNUMBER',		// truncated (safe) card number ("xxxx xxxx xxxx 1234")
									'CARDEXPIRE',		// card expiration
									'SPLITPAYMENT',		// transaction split payment
									'FRAUDPROBABILITY',	// fraud probability if fraud check was performed
									'FRAUDREMARKS',		// fraud remarks if fraudcheck was performed
									'FRAUDREPORT',		// fraud report if given
									'FEE',				// calculated fee
									'$pspsecret'		// Concatenate security code generated at gateway: equivalent to: $this->getAccountParam( 'pspsecret' )
									);
		// Concatenate them using this payments concatenation function with $caseInsensitiveKeys = true:
		$string						=	$this->_concatVars( $requestParams, $listOfParams, null, '', '', false, false, true, false, $rawParams );

		// confirm validation:
		$valid							=	( cbGetParam( $requestParams, 'md5check' ) == $this->_hashString( $string, 'md5', false ) );

		// validate the transaction using the API:
		if ( $valid ) {
			$formvars					=	array();
			$formvars['protocol']		=	'3';
			$formvars['msgtype']		=	'status';
			$formvars['merchant']		=	$this->getAccountParam( 'pspid' );
			$formvars['ordernumber']	=	cbGetParam( $requestParams, 'ordernumber' );
			$formvars['splitpayment']	=	'0';

			$this->_signRequestParams( $formvars );

			$response					=	null;
			$status						=	null;
			$error						=	$this->_httpsRequest( $this->_pspApiUrl(), $formvars, 30, $response, $status, 'post', 'normal', '*/*', true, 443, '', '', true, null );

			if ( ( ! $error ) && ( $status == 200 ) && $response ) {
				$xml_response			=	$this->xmlTagValuesToArray( new SimpleXMLElement( $response, LIBXML_NONET | ( defined('LIBXML_COMPACT') ? LIBXML_COMPACT : 0 ) ) );

				if ( $xml_response ) {
					if ( cbGetParam( $xml_response, 'qpstat' ) == '000' ) {
						$valid			=	true;
					} else {
						$valid			=	false;
					}
				}

				$apiReplyRaw			=	$response;
				$apiReplyArray			=	$xml_response;
			}
		}

		return $valid;
	}

	/**
	 * Compute the CBSubs payment_status based on gateway's reply in $postdata:
	 *
	 * @param  array   $postdata  raw POST data received from the payment gateway
	 * @param  string  $reason    OUT: reason_code
     * @return string             CBSubs status
	 */
	private function _paymentStatus( $postdata, &$reason )
	{
		$status			=	cbGetParam( $postdata, 'qpstat' );

		switch ( $status ) {
			case '000':
				$reason	=	null;
				$status	=	'Completed';
				break;
			case '001':
				$reason	=	'Rejected by acquirer';
				$status	=	'Denied';
				break;
			case '002':
				$reason	=	'Communication error';
				$status	=	'Error';
				break;
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
	 * Popoulates basic request parameters for gateway depending on basket (without specifying payment type)
	 *
	 * @param  cbpaidPaymentBasket  $paymentBasket   paymentBasket object
	 * @return array                                 Returns array $requestParams
	 */
	private function _getBasicRequstParams( $paymentBasket )
	{
		// mandatory parameters:
		$requestParams										=	array();
		$requestParams['protocol']							=	'4';
		$requestParams['msgtype']							=	'authorize';			//FIXME: this should be a param in our config where default should be sale and not authorization ?
		$requestParams['merchant']							=	$this->getAccountParam( 'pspid' );
		$requestParams['language']							=	$this->getAccountParam( 'language' );
		$requestParams['ordernumber']						=	$this->_prepareOrderNumber( $paymentBasket->id, true );
		$requestParams['amount']							=	( sprintf( '%.2f', $paymentBasket->mc_gross ) * 100 );
		$requestParams['currency']							=	$paymentBasket->mc_currency;

		// urls for return, cancel, and IPNs:
		$requestParams['continueurl']						=	$this->getSuccessUrl( $paymentBasket );
		$requestParams['cancelurl']							=	$this->getCancelUrl( $paymentBasket );
		$requestParams['callbackurl']						=	$this->getNotifyUrl( $paymentBasket );

		// optional parameters:
		$requestParams['description']						=	$paymentBasket->item_name;
		$requestParams['testmode']							=	( $this->getAccountParam( 'normal_gateway' ) == '0' ? '1' : '0' );

		// recommended anti-fraud fields:
		$requestParams['CUSTOM_user_id']					=	$paymentBasket->user_id;
		$requestParams['CUSTOM_first_name']					=	$paymentBasket->first_name;
		$requestParams['CUSTOM_last_name']					=	$paymentBasket->last_name;

		if ( $this->getAccountParam( 'givehiddenemail' ) && ( strlen( $paymentBasket->payer_email ) <= 50 ) ) {
			$requestParams['CUSTOM_email']					=	$paymentBasket->payer_email;
		}

		if ( $this->getAccountParam( 'givehiddenddress' ) ) {
			cbimport( 'cb.tabs' ); // needed for cbIsoUtf_substr()

			$addressFields									=	array(	'CUSTOM_address_one' => array( $paymentBasket->address_street, 30 ),
																		'CUSTOM_postal_code' => array( $paymentBasket->address_zip, 10 ),
																		'CUSTOM_city' => array( $paymentBasket->address_city, 30 ),
																		'CUSTOM_country' => array( $this->countryToLetters( $paymentBasket->address_country, 3 ), 3 )
																	);

			if ( $paymentBasket->address_state != 'other' ) {
				$addressFields['CUSTOM_state_or_province']	=	array( substr( $paymentBasket->address_state, -2 ), 2 );
			}

			foreach ( $addressFields as $k => $value_maxlength ) {
				$adrField									=	cbIsoUtf_substr( $value_maxlength[0], 0, $value_maxlength[1] );

				if ( $adrField ) {
					$requestParams[$k]						=	$adrField;
				}
			}
		}

		if ( $this->getAccountParam( 'givehiddentelno' ) && ( strlen( $paymentBasket->contact_phone ) <= 50 ) ) {
			$requestParams['CUSTOM_phone']					=	$paymentBasket->contact_phone;
		}

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

		$ret													=	null;
		$paymentBasketId										=	(int) $this->_prepareOrderNumber( cbGetParam( $requestdata, 'ordernumber', null ) );

		if ( $paymentBasketId ) {
			$exists												=	$paymentBasket->load( (int) $paymentBasketId );

			if ( $exists && ( ( cbGetParam( $requestdata, $this->_getPagingParamName( 'id' ), 0 ) == $paymentBasket->shared_secret ) && ( ! ( ( ( $type == 'R' ) || ( $type == 'I' ) ) && ( $paymentBasket->payment_status == 'Completed' ) ) ) ) ) {
				// PDT doesn't return transacton information; lets request for it:
				if ( $type == 'R' ) {
					$formvars									=	array();
					$formvars['protocol']						=	'3';
					$formvars['msgtype']						=	'status';
					$formvars['merchant']						=	$this->getAccountParam( 'pspid' );
					$formvars['ordernumber']					=	$this->_prepareOrderNumber( $paymentBasket->id, true );
					$formvars['splitpayment']					=	'0';

					$this->_signRequestParams( $formvars );

					$response									=	null;
					$status										=	null;
					$error										=	$this->_httpsRequest( $this->_pspApiUrl(), $formvars, 30, $response, $status, 'post', 'normal', '*/*', true, 443, '', '', true, null );

					if ( ( ! $error ) && ( $status == 200 ) && $response ) {
						$xml_response							=	$this->xmlTagValuesToArray( new SimpleXMLElement( $response, LIBXML_NONET | ( defined('LIBXML_COMPACT') ? LIBXML_COMPACT : 0 ) ) );

						if ( $xml_response ) {
							$requestdata						=	$xml_response;
						}
					}
				}

				// Log the return record:
				$log_type										=	$type;
				$reason											=	null;
				$paymentStatus									=	$this->_paymentStatus( $requestdata, $reason );
				$paymentType									=	$this->_getPaymentType( $requestdata );
				$paymentTime									=	$_CB_framework->now();

				if ( $paymentStatus == 'Error' ) {
					$errorTypes									=	array( 'I' => 'D', 'R' => 'E' );

					if ( isset( $errorTypes[$type] ) ) {
						$log_type								=	$errorTypes[$type];
					}
				}

				$ipn											=	$this->_prepareIpn( $log_type, $paymentStatus, $paymentType, $reason, $paymentTime, 'utf-8' );

				if ( $paymentStatus == 'Refunded' ) {
					// in case of refund we need to log the payment as it has same TnxId as first payment: so we need payment_date for discrimination:
					$ipn->payment_date							=	gmdate( 'H:i:s M d, Y T', $paymentTime ); // paypal-style
				}

				$ipn->test_ipn									=	( ( $this->getAccountParam( 'normal_gateway' ) == '0' ) || (int) cbGetParam( $requestdata, 'testmode' ) ? 1 : 0 );
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
					$paymentBasket->reason_code					=	$reason;

					$this->_storeIpnResult( $ipn, 'ERROR:' . $reason );
					$this->_setLogErrorMSG( 4, $ipn, $this->getPayName() . ': ' . $reason, CBPTXT::T( 'Sorry, the payment server replied with an error.' ) . ' ' . CBPTXT::T( 'Please contact site administrator to check payment status and error log.' ) );

					$ret										=	false;
				} else {
					$ipn->bindBasket( $paymentBasket );

					$ipn->sale_id								=	$paymentBasketId;

					$insToIpn									=	array(	'txn_id' => 'transaction',
																			'mc_currency' => 'currency',
																			'receiver_email' => 'merchantemail',
																			'first_name' => 'CUSTOM_first_name',
																			'last_name' => 'CUSTOM_last_name',
																			'address_street' => 'CUSTOM_address_one',
																			'address_zip' => 'CUSTOM_postal_code',
																			'address_city' => 'CUSTOM_city',
																			'address_country' => 'CUSTOM_country',
																			'address_state' => 'CUSTOM_state_or_province',
																			'contact_phone' => 'CUSTOM_phone',
																			'payer_email' => 'CUSTOM_email'
																		);

					foreach ( $insToIpn as $k => $v ) {
						$ipn->$k								=	cbGetParam( $requestdata, $v );
					}

					$ipn->mc_gross								=	sprintf( '%.2f', ( cbGetParam( $requestdata, 'amount' ) / 100 ) );
					$ipn->user_id								=	(int) $paymentBasket->user_id;

					// check what type of purchase this is:
					$recurring									=	( in_array( cbGetParam( $requestdata, 'msgtype' ), array( 'subscribe', 'recurring' ) ) ? true : false );

					// handle recurring subscriptions properly or default to single payment:
					if ( $recurring ) {
						if ( ( $paymentStatus == 'Completed' ) && ( ! $paymentBasket->subscr_id ) ) {
							$ipn->txn_type						=	'subscr_signup';
							$ipn->subscr_id						=	cbGetParam( $requestdata, 'transaction' );
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
					if ( $this->_pspVerifySignature( $requestdata, $apiReplyRaw, $apiReplyArray, true ) ) {
						$ipn->raw_data							.=	/* cbGetParam() not needed: we want raw info */ '$apiReplyRaw=\'' . str_replace( array( '\\', '\'' ), array( '\\\\', '\\\'' ), $apiReplyRaw ) . "';\n"
																.	/* cbGetParam() not needed: we want raw info */ '$apiReplyFormattedArray=' . var_export( $apiReplyArray, true ) . ";\n"
																;
						if ( ( $paymentBasketId == $this->_prepareOrderNumber( cbGetParam( $requestdata, 'ordernumber', null ) ) ) && ( ( sprintf( '%.2f', $paymentBasket->mc_gross ) == $ipn->mc_gross ) || ( $ipn->payment_status == 'Refunded' ) ) && ( $paymentBasket->mc_currency == $ipn->mc_currency ) ) {
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
						$this->_setLogErrorMSG( 3, $ipn, $this->getPayName() . ': md5check or transaction does not match with gateway. Please check Secret Key setting', CBPTXT::T( 'The Secret Key signature is incorrect.' ) . ' ' . CBPTXT::T( 'Please contact site administrator to check error log.' ) );

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
