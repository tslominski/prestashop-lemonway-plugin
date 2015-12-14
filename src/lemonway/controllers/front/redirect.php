<?php
/**
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2015 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

class LemonwayRedirectModuleFrontController extends ModuleFrontController
{
	
	protected $_supportedLangs = array('no', 'jp', 'ko', 'sp', 'fr', 'xz', 'ge', 'it', 'br', 'da', 'fi', 'sw', 'po', 'fl', 'ci', 'pl','ne');
	protected $_defaultLang    = 'en';
	
	public function __construct(){
		parent::__construct();
		require_once _PS_MODULE_DIR_.$this->module->name.'/services/LemonWayKit.php';
	}
	
	
    /**
     * Do whatever you have to before redirecting the customer on the website of your payment processor.
     */
    public function postProcess()
    {
    	$cart = $this->context->cart;
    	/* @var $customer CustomerCore */
    	$customer = $this->context->customer;
    	
    	$secure_key = $this->context->customer->secure_key;
    	$kit = new LemonWayKit();
    	
    	$params = array();
    	if(!$this->useCard())
    	{
    		
	    	//call directkit to get Webkit Token
	    	$params = array('wkToken'=>$cart->id,//sprintf("%04d" ,$cart->id),
	    			'wallet'=> LemonWayConfig::getWalletMerchantId(),
	    			'amountTot'=>number_format((float)$cart->getOrderTotal(true, 3), 2, '.', ''),
	    			'amountCom'=>number_format((float)0, 2, '.', ''),//because money is transfered in merchant wallet
	    			'comment'=>'',
	    			'returnUrl'=>urlencode($this->context->link->getModuleLink('lemonway', 'validation', array('register_card'=>(int)$this->registerCard(),'action' => 'return', 'secure_key' => $secure_key) , true)),
	    			'cancelUrl'=>urlencode($this->context->link->getModuleLink('lemonway', 'validation',  array('action' => 'cancel', 'secure_key' => $secure_key), true)),
	    			'errorUrl'=>urlencode($this->context->link->getModuleLink('lemonway', 'validation', array('action' => 'error', 'secure_key' => $secure_key), true)),
	    			'autoCommission'=>0,
	    			'registerCard'=>$this->registerCard(), //For Atos //@TODO get value from payment form
	    			'useRegisteredCard'=>$this->registerCard(), //For payline //@TODO get value from payment form
	    	);
	    	
		   	try {
		   		
		    	$res = $kit->MoneyInWebInit($params);
		    	
		    	/**
		    	 * Oops, an error occured.
		    	 */
		    	if(isset($res->lwError)){
		    		throw new Exception((string)$res->lwError->MSG, (int)$res->lwError->CODE);
		    	}
		    	
		    	if($customer->id && isset($res->lwXml->MONEYINWEB->CARD) && $this->registerCard())
		    	{
		    	
		    		$card = $this->module->getCustomerCard($customer->id);
		    		if(!$card)
		    			$card = array();
		    	
		    		$card['id_customer'] = $customer->id;
		    		$card['id_card'] = (string)$res->lwXml->MONEYINWEB->CARD->ID;
		    		
		    		$this->module->insertOrUpdateCard($customer->id,$card);
		    		
		    	}
		    	
		    	
		   	} catch (Exception $e) {
		   		$this->addError($e->getMessage());
		   		return $this->displayError();
		   		
		   	}
	    	
	        
	    	$moneyInToken = (string)$res->lwXml->MONEYINWEB->TOKEN;
	    		
	    	$language = $this->getLang();
	    	Tools::redirect(LemonWayConfig::getWebkitUrl() . '?moneyintoken='.$moneyInToken.'&p='.urlencode(LemonWayConfig::getCssUrl()).'&lang='.$language);
	    		
	    	
    	}
    	else{
    		if(($card = $this->module->getCustomerCard($customer->id)) && $customer->isLogged())
    		{

    			//call directkit for MoneyInWithCardId
    			$params = array(
    					'wkToken'=>$cart->id,//sprintf("%04d" ,$cart->id),
	    				'wallet'=> LemonWayConfig::getWalletMerchantId(),
	    				'amountTot'=>number_format((float)$cart->getOrderTotal(true, 3), 2, '.', ''),
	    				'amountCom'=>number_format((float)0, 2, '.', ''),
    					'message'=> sprintf($this->module->l('Money In with Card Id for cart %s'),(string)$cart->id),
    					'autoCommission'=>0,
    					'cardId'=>$card['id_card'],
    					'isPreAuth'=>0,
    					'specialConfig'=>'',
    					'delayedDays'=>6 //not used because isPreAuth always false
    			);
    			 
    			
    			try {
    				 
    				$res = $kit->MoneyInWithCardId($params);
    				
    			} catch (Exception $e) {
    				$this->addError($e->getMessage());
    				return $this->displayError();
    				 
    			}
 
    			 
    			if (isset($res->lwError)){
    				$this->addError('An error occurred while trying to pay with your registered card',"Error code: " . $res->lwError->CODE . " Message: " . $res->lwError->MSG);
	    			return $this->displayError();
    			}
    			 
    			/* @var $op Operation */
    			foreach ($res->operations as $op) {
    					
    				if($op->STATUS == "3")
    				{
    					$payment_status = Configuration::get('PS_OS_PAYMENT');
    					$message = Tools::getValue('response_msg');
    					$module_name = $this->module->displayName;
    					$currency_id = (int)$this->context->currency->id;
    					$amount = number_format((float)$cart->getOrderTotal(true, 3), 2, '.', '');
						
    					$this->module->validateOrder($cart->id, $payment_status, $amount, $module_name, $message, array(), $currency_id, false, $secure_key);
    					
    					$order_id = Order::getOrderByCartId((int)$cart->id);
    					if($order_id)
    					{
    						$module_id = $this->module->id;
    						return Tools::redirect('index.php?controller=order-confirmation&id_cart='.$cart->id.'&id_module='.$module_id.'&id_order='.$order_id.'&key='.$secure_key);
    						 
    					}
    					else {
    						$this->addError("Error while saving order!");
    						return $this->displayError();
    					}
    					
    					break;
    				}
    				else{
    					$this->addError($op->MSG);
    					return $this->displayError();
    				}
    					
    			}
    			 
    		}
    		else{
    			$this->addError('Customer not logged or card not found!');
	    			return $this->displayError();
    		}
    		
    	}
    }
    
    protected function registerCard(){
    	
    	return Tools::getValue('lw_oneclic') === 'register_card';
    }
    
    protected function useCard(){
    	return Tools::getValue('lw_oneclic') === 'use_card';
    }
    
    /**
     * Return current lang code
     *
     * @return string
     */
    protected function getLang()
    {
    	
    	if (in_array($this->context->language->iso_code, $this->_supportedLangs)) {
    		return $this->context->language->iso_code;
    	}
    	
    	return $this->_defaultLang;
    }
    
    protected function addError($message, $description = false){
    	/**
    	 * Set error message and description for the template.
    	 */
    	array_push($this->errors, $this->module->l($message), $description);
    }

    protected function displayError()
    {
        /**
         * Create the breadcrumb for your ModuleFrontController.
         */
        $this->context->smarty->assign('path', '
			<a href="'.$this->context->link->getPageLink('order', null, null, 'step=3').'">'.$this->module->l('Payment').'</a>
			<span class="navigation-pipe">&gt;</span>'.$this->module->l('Error'));
        

        return $this->setTemplate('error.tpl');
    }
}