<?php
/**
 * 2007-2016 [PagSeguro Internet Ltda.]
 *
 * NOTICE OF LICENSE
 *
 *Licensed under the Apache License, Version 2.0 (the "License");
 *you may not use this file except in compliance with the License.
 *You may obtain a copy of the License at
 *
 *http://www.apache.org/licenses/LICENSE-2.0
 *
 *Unless required by applicable law or agreed to in writing, software
 *distributed under the License is distributed on an "AS IS" BASIS,
 *WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *See the License for the specific language governing permissions and
 *limitations under the License.
 *
 *  @author    PagSeguro Internet Ltda.
 *  @copyright 2016 PagSeguro Internet Ltda.
 *  @license   http://www.apache.org/licenses/LICENSE-2.0
 */
namespace UOL\PagSeguro\Model\Direct;

use UOL\PagSeguro\Helper\Library;
/**
 * Class PaymentMethod
 * @package UOL\PagSeguro\Model
 */
class CreditCardMethod
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig;
    
    /**
     *
     * @var \PagSeguro\Domains\Requests\Payment
     */
    protected $_paymentRequest;
    /**
     *
     * @var \Magento\Directory\Api\CountryInformationAcquirerInterface
     */
    protected $_countryInformation;
    /**
     * PaymentMethod constructor.
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfigInterface
     * @param \Magento\Checkout\Model\Session $checkoutSession
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfigInterface,
        \Magento\Sales\Model\Order $order,
        \Magento\Directory\Api\CountryInformationAcquirerInterface $countryInformation,
		\Magento\Framework\Module\ModuleList $moduleList
    ) {
        $this->_scopeConfig = $scopeConfigInterface;
        $this->_order = $order;
        $this->_countryInformation = $countryInformation;
		$this->_library = new Library($scopeConfigInterface, $moduleList);
        $this->_paymentRequest = new \PagSeguro\Domains\Requests\DirectPayment\CreditCard();
    }
    /**
     * @return \PagSeguroPaymentRequest
     */
    public function createPaymentRequest()
    {
        // Currency
        $this->_paymentRequest->setCurrency("BRL");
        // Order ID
        $this->_paymentRequest->setReference($this->getOrderStoreReference());
        //Shipping
        $this->setShippingInformation();
        $this->_paymentRequest->setShipping()->setType()
            ->withParameters(\PagSeguro\Enum\Shipping\Type::NOT_SPECIFIED); //Shipping Type
        $this->_paymentRequest->setShipping()->setCost()
            ->withParameters(number_format($this->getShippingAmount(), 2, '.', '')); //Shipping Coast
        
        //Billing
        $this->setBillingInformation();
        //        $this->_paymentRequest->setBilling()->setType()
        //            ->withParameters(\PagSeguro\Enum\Shipping\Type::NOT_SPECIFIED); //Shipping Type
        // Sender
        $this->setSenderInformation();
        // Itens
        $this->setItemsInformation();
        //Redirect Url
        $this->_paymentRequest->setRedirectUrl($this->getRedirectUrl());
        // Notification Url
        $this->_paymentRequest->setNotificationUrl($this->getNotificationUrl());
        try {
            $this->_library->setEnvironment();
            $this->_library->setCharset();
            $this->_library->setLog();

            return $this->_paymentRequest->register(
                $this->_library->getPagSeguroCredentials()
            );
        } catch (PagSeguroServiceException $ex) {
            $this->logger->debug($ex->getMessage());
            $this->getCheckoutRedirectUrl();
        }
    }

    /**
     * Set installments
     *
     * @param $name
     */
    public function setInstallment($quantity, $amount)
    {
        $this->_paymentRequest->setInstallment()->withParameters($quantity, $amount);
    }
    
    /**
     * Set credit card token
     *
     * @param $name
     * @return void
     */
    public function setToken($token)
    {
        $this->_paymentRequest->setToken($token);
    }
    
    /**
     * Set the holder informartion
     * @param string $name
     * @param string $birthdate
     * @param array $document
     * @return void
     */
    public function setHolder($name, $birthdate, $document)
    {
        $this->_paymentRequest->setHolder()->setName($name);
        $this->_paymentRequest->setHolder()->setBirthdate($birthdate);
        $this->_paymentRequest->setHolder()->setDocument()->withParameters(
            $document['type'],
            $document['number']
        );
        $this->setHolderPhone();
    }
    
    /**
     * Set the holder phone
     * @return void
     */
    private function setHolderPhone()
    {
        $shipping = $this->getShippingData();
        if (! empty($shipping['telephone'])) {
            $phone = \UOL\PagSeguro\Helper\Data::formatPhone($shipping['telephone']);
            $this->_paymentRequest->setHolder()->setPhone()->withParameters(
                $phone['areaCode'],
                $phone['number']
            );
        }
    }
    
    /**
     * Get the billing information and set in the attribute $_paymentRequest
     */
    private function setBillingInformation()
    {
        $billing = $this->getBillingData();
        $country = $this->getCountryName($billing['country_id']);
        $address = \UOL\PagSeguro\Helper\Data::addressConfig($billing['street']);

        $this->_paymentRequest->setBilling()->setAddress()->withParameters(
            $this->getShippingAddress($address[0], $billing),
            $this->getShippingAddress($address[1]),
            $this->getShippingAddress($address[0]),
            \UOL\PagSeguro\Helper\Data::fixPostalCode($billing['postcode']),
            $billing['city'],
            $this->getRegionAbbreviation($billing['region']),
            $country,
            $this->getShippingAddress($address[2])
        );
    }
    
    /**
     * Get the billing Data of the Order
     * @return object $orderParams - Return parameters, of billing of order
     */
    private function getBillingData()
    {
        $billingAddress = $this->getBillingAddress();
        
        return (!empty($billingAddress)) ? 
            $billingAddress : 
            $this->_order->getShippingAddress();
    }

    /**
     * Set sender hash
     *
     * @param $hash
     */
    public function setSenderHash($hash)
    {
        $this->_paymentRequest->setSender()->setHash(htmlentities($hash));
    }

    /**
     * Set sender document
     *
     * @param $document
     */
    public function setSenderDocument($document)
    {
        $this->_paymentRequest->setSender()->setDocument()->withParameters(
            $document['type'],
            $document['number']
        );
    }

    /**
     * Get information of purchased items and set in the attribute $_paymentRequest
     */
    private function setItemsInformation()
    {
        foreach ($this->_order->getAllVisibleItems() as $product) {
            $this->_paymentRequest->addItems()->withParameters(
                $product->getId(), //id
                \UOL\PagSeguro\Helper\Data::fixStringLength($product->getName(), 255), //description
                $product->getSimpleQtyToShip(), //quantity
                \UOL\PagSeguro\Helper\Data::toFloat($product->getPrice()), //amount
                round($product->getWeight()) //weight
            );
        }
    }

    /**
     * Get customer information that are sent and set in the attribute $_paymentRequest
     */
    private function setSenderInformation()
    {
        $senderName = $this->_order->getCustomerName();

        if ($senderName == __('Guest')) {
            $address = $this->getBillingAddress();
            $senderName = $address->getFirstname() . ' ' . $address->getLastname();
        }
        $this->_paymentRequest->setSender()->setName($senderName);
        $this->_paymentRequest->setSender()->setEmail($this->getEmail());
        $this->setSenderPhone();
    }

    /**
     * Return a mock for sandbox if this is the active environment
     *
     * @return string
     */
    private function getEmail()
    {
        if ($this->_scopeConfig->getValue('payment/pagseguro/environment') == "sandbox") {
            return "magento2@sandbox.pagseguro.com.br"; //mock for sandbox
        }
        return $this->_order->getCustomerEmail();
    }

    /**
     * Set the sender phone if it exist
     */
    private function setSenderPhone()
    {
        $shipping = $this->getShippingData();
        if (! empty($shipping['telephone'])) {
            $phone = \UOL\PagSeguro\Helper\Data::formatPhone($shipping['telephone']);
            $this->_paymentRequest->setSender()->setPhone()->withParameters(
                $phone['areaCode'],
                $phone['number']
            );
        }
    }

    /**
     * Get the shipping information and set in the attribute $_paymentRequest
     */
    private function setShippingInformation()
    {
        $shipping = $this->getShippingData();
        $country = $this->getCountryName($shipping['country_id']);
        $address = \UOL\PagSeguro\Helper\Data::addressConfig($shipping['street']);

        $this->_paymentRequest->setShipping()->setAddress()->withParameters(
            $this->getShippingAddress($address[0], $shipping),
            $this->getShippingAddress($address[1]),
            $this->getShippingAddress($address[0]),
            \UOL\PagSeguro\Helper\Data::fixPostalCode($shipping['postcode']),
            $shipping['city'],
            $this->getRegionAbbreviation($shipping['region']),
            $country,
            $this->getShippingAddress($address[2])
        );
    }

    /**
     * @param $address
     * @param bool $shipping
     * @return array|null
     */
    private function getShippingAddress($address, $shipping = null)
    {
        if (!is_null($address) or !empty($adress)) {
            return $address;
        }
        if ($shipping) {
            return \UOL\PagSeguro\Helper\Data::addressConfig($shipping['street']);
        }
        return null;
    }
    /**
     * Get the shipping Data of the Order
     * @return object $orderParams - Return parameters, of shipping of order
     */
    private function getShippingData()
    {
        if ($this->_order->getIsVirtual()) {
            return $this->getBillingAddress();
        }
        return $this->_order->getShippingAddress();
    }

    /**
     * @return mixed
     */
    private function getShippingAmount()
    {
        return $this->_order->getBaseShippingAmount();
    }

    /**
     * @return string
     */
    private function getOrderStoreReference()
    {
        return \UOL\PagSeguro\Helper\Data::getOrderStoreReference(
            $this->_scopeConfig->getValue('pagseguro/store/reference'),
            $this->_order->getEntityId()
        );
    }

    /**
     * Get a brazilian region name and return the abbreviation if it exists
     * @param string $regionName
     * @return string
     */
    private function getRegionAbbreviation($regionName)
    {
        $regionAbbreviation = new \PagSeguro\Enum\Address();
        return (is_string($regionAbbreviation->getType($regionName))) ? $regionAbbreviation->getType($regionName) : $regionName;
    }

    /**
     * Get the store notification url
     * @return string
     */
    public function getNotificationUrl()
    {
        return $this->_scopeConfig->getValue('payment/pagseguro/notification');
    }

    /**
     * Get the store redirect url
     * @return string
     */
    public function getRedirectUrl()
    {
        return $this->_scopeConfig->getValue('payment/pagseguro/redirect');
    }


    /**
     * Get the billing address data of the Order
     * @return \Magento\Sales\Model\Order\Address|null
     */
    private function getBillingAddress()
    {
        return $this->_order->getBillingAddress();
    }

	/**
     * Get the country name based on the $countryId
     *
     * @param string $countryId
     * @return string
     */
    private function getCountryName($countryId)
    {
        return (!empty($countryId)) ?
            $this->_countryInformation->getCountryInfo($countryId)->getFullNameLocale() :
            $countryId;
    }
}