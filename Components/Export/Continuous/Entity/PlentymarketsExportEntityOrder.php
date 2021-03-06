<?php
/**
 * plentymarkets shopware connector
 * Copyright © 2013 plentymarkets GmbH
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License, supplemented by an additional
 * permission, and of our proprietary license can be found
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "plentymarkets" is a registered trademark of plentymarkets GmbH.
 * "shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, titles and interests in the
 * above trademarks remain entirely with the trademark owners.
 *
 * @copyright  Copyright (c) 2013, plentymarkets GmbH (http://www.plentymarkets.com)
 * @author     Daniel Bächtle <daniel.baechtle@plentymarkets.com>
 */

require_once PY_SOAP . 'Models/PlentySoapObject/DeliveryAddress.php';
require_once PY_SOAP . 'Models/PlentySoapObject/Order.php';
require_once PY_SOAP . 'Models/PlentySoapObject/OrderDocumentNumbers.php';
require_once PY_SOAP . 'Models/PlentySoapObject/OrderHead.php';
require_once PY_SOAP . 'Models/PlentySoapObject/OrderIncomingPayment.php';
require_once PY_SOAP . 'Models/PlentySoapObject/OrderInfo.php';
require_once PY_SOAP . 'Models/PlentySoapObject/OrderItem.php';
require_once PY_SOAP . 'Models/PlentySoapObject/SalesOrderProperty.php';
require_once PY_SOAP . 'Models/PlentySoapObject/String.php';
require_once PY_SOAP . 'Models/PlentySoapRequest/AddOrders.php';
require_once PY_COMPONENTS . 'Export/PlentymarketsExportEntityException.php';
require_once PY_COMPONENTS . 'Export/Entity/PlentymarketsExportEntityCustomer.php';
require_once PY_COMPONENTS . 'Export/Continuous/Entity/PlentymarketsExportEntityOrderIncomingPayment.php';

/**
 * PlentymarketsExportEntityOrder provides the actual items export funcionality. Like the other export
 * entities this class is called in PlentymarketsExportController. It is important to deliver valid
 * order ID to the constructor method of this class.
 * The data export takes place based on plentymarkets SOAP-calls.
 *
 * @author Daniel Bächtle <daniel.baechtle@plentymarkets.com>
 */
class PlentymarketsExportEntityOrder
{

	/**
	 *
	 * @var integer
	 */
	const CODE_SUCCESS = 2;

	/**
	 *
	 * @var integer
	 */
	const CODE_ERROR_CUSTOMER = 1;

	/**
	 *
	 * @var integer
	 */
	const CODE_ERROR_MOP = 4;

	/**
	 *
	 * @var integer
	 */
	const CODE_ERROR_SOAP = 8;

	/**
	 *
	 * @var PDOStatement
	 */
	protected static $StatementGetSKU = null;

	/**
	 *
	 * @var array
	 */
	protected $order;

	/**
	 *
	 * @var integer
	 */
	protected $PLENTY_customerID;

	/**
	 *
	 * @var integer|null
	 */
	protected $PLENTY_addressDispatchID;

	/**
	 * Constructor method
	 *
	 * @param unknown $orderID
	 */
	public function __construct($orderID)
	{
		$OrderResource = \Shopware\Components\Api\Manager::getResource('Order');
		try
		{
			$this->order = $OrderResource->getOne($orderID);
		}
		catch (\Shopware\Components\Api\Exception\NotFoundException $E)
		{
		}

		if (is_null(self::$StatementGetSKU))
		{
			self::$StatementGetSKU = Shopware()->Db()->prepare('
				SELECT kind, id detailsID, articleID
					FROM s_articles_details
					WHERE ordernumber = ?
					LIMIT 1
			');
		}
	}

	/**
	 * Exports the customer and the order respectively
	 */
	public function export()
	{
		$this->exportCustomer();
		$this->exportOrder();
	}

	/**
	 * Export the customer
	 *
	 * @return boolean
	 */
	protected function exportCustomer()
	{
		$Customer = Shopware()->Models()->find('Shopware\Models\Customer\Customer', $this->order['customer']['id']);
		$Billing = Shopware()->Models()->find('Shopware\Models\Order\Billing', $this->order['billing']['id']);

		if (!is_null($this->order['shipping']))
		{
			$Shipping = Shopware()->Models()->find('Shopware\Models\Order\Shipping', $this->order['shipping']['id']);
		}
		else
		{
			$Shipping = null;
		}

		//
		try
		{
			$PlentymarketsExportEntityOrderCustomer = new PlentymarketsExportEntityCustomer($Customer, $Billing, $Shipping);
			$PlentymarketsExportEntityOrderCustomer->export();
		}
		catch (PlentymarketsExportEntityException $E)
		{
			// Save the error
			$this->setError(self::CODE_ERROR_CUSTOMER);

			// Throw another exception
			throw new PlentymarketsExportEntityException('The order with the number »' . $this->order['number'] . '« could not be exported (' . $E->getMessage() . ')', 4100);
		}

		//
		$this->PLENTY_customerID = $PlentymarketsExportEntityOrderCustomer->getPlentyCustomerID();
		$this->PLENTY_addressDispatchID = $PlentymarketsExportEntityOrderCustomer->getPlentyAddressDispatchID();
	}

	/**
	 * Export the order
	 */
	protected function exportOrder()
	{
		// Mapping für Versand
		try
		{
			list ($parcelServicePresetID, $parcelServiceID) = explode(';', PlentymarketsMappingController::getShippingProfileByShopwareID($this->order['dispatchId']));
		}
		catch (PlentymarketsMappingExceptionNotExistant $E)
		{
			$parcelServicePresetID = null;
			$parcelServiceID = null;
		}

		try
		{
			$methodOfPaymentId = PlentymarketsMappingController::getMethodOfPaymentByShopwareID($this->order['payment']['id']);
		}
		catch (PlentymarketsMappingExceptionNotExistant $E)
		{
			// Save the error
			$this->setError(self::CODE_ERROR_MOP);

			// Quit
			throw new PlentymarketsExportEntityException('The order with the number »' . $this->order['number'] . '« could not be exported (no mapping for method of payment)', 4030);
		}

		// Shipping costs
		$shippingCosts = $this->order['invoiceShipping'] >= 0 ? $this->order['invoiceShipping'] : null;

		// Build the Request
		$Request_AddOrders = new PlentySoapRequest_AddOrders();
		$Request_AddOrders->Orders = array();

		//
		$Object_Order = new PlentySoapObject_Order();

		$Object_OrderHead = new PlentySoapObject_OrderHead();
		$Object_OrderHead->Currency = PlentymarketsMappingController::getCurrencyByShopwareID($this->order['currency']);
		$Object_OrderHead->CustomerID = $this->PLENTY_customerID; // int
		$Object_OrderHead->DeliveryAddressID = $this->PLENTY_addressDispatchID; // int
		$Object_OrderHead->DoneTimestamp = null; // string
		$Object_OrderHead->ExchangeRatio = null; // float
		$Object_OrderHead->ExternalOrderID = sprintf('Swag/%d/%s', $this->order['id'], $this->order['number']); // string
		$Object_OrderHead->IsNetto = false; // boolean
		$Object_OrderHead->Marking1ID = PlentymarketsConfig::getInstance()->getOrderMarking1(null); // int
		$Object_OrderHead->MethodOfPaymentID = $methodOfPaymentId; // int
		$Object_OrderHead->OrderTimestamp = $this->order['orderTime']->getTimestamp(); // int
		$Object_OrderHead->OrderType = 'order'; // string
		$Object_OrderHead->ResponsibleID = PlentymarketsConfig::getInstance()->getOrderUserID(null); // int
		$Object_OrderHead->ShippingCosts = $shippingCosts; // float
		$Object_OrderHead->ShippingMethodID = $parcelServiceID; // int
		$Object_OrderHead->ShippingProfileID = $parcelServicePresetID; // int


		try
		{
			$Object_OrderHead->StoreID = PlentymarketsMappingController::getShopByShopwareID($this->order['shopId']);
		}
		catch(PlentymarketsMappingExceptionNotExistant $E)
		{
		}

		// Referrer
		if ($this->order['partnerId'] > 0)
		{
			try
			{
				$referrerId = PlentymarketsMappingController::getReferrerByShopwareID($this->order['partnerId']);
			}
			catch (PlentymarketsMappingExceptionNotExistant $E)
			{
				$referrerId = PlentymarketsConfig::getInstance()->getOrderReferrerID();
			}
		}
		else
		{
			$referrerId = PlentymarketsConfig::getInstance()->getOrderReferrerID();
		}

		$Object_OrderHead->ReferrerID = $referrerId;

		$Object_Order->OrderHead = $Object_OrderHead;

		$Object_OrderHead->OrderInfos = array();

		// Debit data
		if (isset($this->order['customer']['debit']['accountHolder']) && $Object_OrderHead->MethodOfPaymentID == MOP_DEBIT)
		{
			$info  = 'Account holder: '. $this->order['customer']['debit']['accountHolder'] . chr(10);
			$info .= 'Bank name: '. $this->order['customer']['debit']['bankName'] . chr(10);
			$info .= 'Bank code: '. $this->order['customer']['debit']['bankCode'] . chr(10);
			$info .= 'Account number: '. $this->order['customer']['debit']['account'] . chr(10);

			$Object_OrderInfo = new PlentySoapObject_OrderInfo();
			$Object_OrderInfo->Info = $info;
			$Object_OrderInfo->InfoCustomer = 0;
			$Object_OrderInfo->InfoDate = $this->order['orderTime']->getTimestamp();
			$Object_OrderHead->OrderInfos[] = $Object_OrderInfo;
		}

		if (!empty($this->order['internalComment']))
		{
			$Object_OrderInfo = new PlentySoapObject_OrderInfo();
			$Object_OrderInfo->Info = $this->order['internalComment'];
			$Object_OrderInfo->InfoCustomer = 0;
			$Object_OrderInfo->InfoDate = $this->order['orderTime']->getTimestamp();
			$Object_OrderHead->OrderInfos[] = $Object_OrderInfo;
		}

		if (!empty($this->order['customerComment']))
		{
			$Object_OrderInfo = new PlentySoapObject_OrderInfo();
			$Object_OrderInfo->Info = $this->order['customerComment'];
			$Object_OrderInfo->InfoCustomer = 1;
			$Object_OrderInfo->InfoDate = $this->order['orderTime']->getTimestamp();
			$Object_OrderHead->OrderInfos[] = $Object_OrderInfo;
		}

		if (!empty($this->order['comment']))
		{
			$Object_OrderInfo = new PlentySoapObject_OrderInfo();
			$Object_OrderInfo->Info = $this->order['comment'];
			$Object_OrderInfo->InfoCustomer = 1;
			$Object_OrderInfo->InfoDate = $this->order['orderTime']->getTimestamp();
			$Object_OrderHead->OrderInfos[] = $Object_OrderInfo;
		}

		$Object_Order->OrderItems = array();

		foreach ($this->order['details'] as $item)
		{
			self::$StatementGetSKU->execute(array(
				$item['articleNumber']
			));

			// Fetch the item
			$sw_OrderItem = self::$StatementGetSKU->fetchObject();

			// Variant
			try
			{
				$itemId = null;
				$sku = PlentymarketsMappingController::getItemVariantByShopwareID($sw_OrderItem->detailsID);
			}

			catch (PlentymarketsMappingExceptionNotExistant $E)
			{
				// Base item
				try
				{
					$itemId = PlentymarketsMappingController::getItemByShopwareID($sw_OrderItem->articleID);
					$sku = null;
				}

				// Unknown item
				catch (PlentymarketsMappingExceptionNotExistant $E)
				{
					$itemId = -2;
					$sku = null;

					// Mandatory because there will be no mapping to any item
					$itemText = $item['articleName'];
				}
			}

			//
			if ($itemId > 0 || !empty($sku))
			{
				if (PlentymarketsConfig::getInstance()->getOrderItemTextSyncActionID(EXPORT_ORDER_ITEM_TEXT_SYNC) == EXPORT_ORDER_ITEM_TEXT_SYNC)
				{
					$itemText = $item['articleName'];
				}
				else
				{
					$itemText = null;
				}
			}

			// Gutschein
			if ($item['mode'] == 2)
			{
				$itemId = -1;
			}

			$Object_OrderItem = new PlentySoapObject_OrderItem();
			$Object_OrderItem->ExternalOrderItemID = $item['articleNumber']; // string
			$Object_OrderItem->ItemID = $itemId; // int
			$Object_OrderItem->ReferrerID = $Object_OrderHead->ReferrerID;
			$Object_OrderItem->ItemText = $itemText; // string
			$Object_OrderItem->Price = $item['price']; // float
			$Object_OrderItem->Quantity = $item['quantity']; // float
			$Object_OrderItem->SKU = $sku; // string
			$Object_OrderItem->VAT = $item['taxRate']; // float
			$Object_OrderItem->WarehouseID = null; // int
			$Object_Order->OrderItems[] = $Object_OrderItem;
		}

		$Request_AddOrders->Orders[] = $Object_Order;

		// Do the request
		$Response_AddOrders = PlentymarketsSoapClient::getInstance()->AddOrders($Request_AddOrders);

		if (!$Response_AddOrders->Success)
		{
			// Set the error end quit
			$this->setError(self::CODE_ERROR_SOAP);
			throw new PlentymarketsExportEntityException('The order with the number »' . $this->order['number'] . '« could not be exported', 4010);
		}

		//
		$plentyOrderID = null;
		$plentyOrderStatus = 0.00;

		foreach ($Response_AddOrders->ResponseMessages->item[0]->SuccessMessages->item as $SuccessMessage)
		{
			switch ($SuccessMessage->Key)
			{
				case 'OrderID':
					$plentyOrderID = (integer) $SuccessMessage->Value;
					break;

				case 'Status':
					$plentyOrderStatus = (float) $SuccessMessage->Value;
					break;
			}
		}

		if ($plentyOrderID && $plentyOrderStatus)
		{
			$this->setSuccess($plentyOrderID, $plentyOrderStatus);
		}
		else
		{
			// Set the error end quit
			$this->setError(self::CODE_ERROR_SOAP);
			throw new PlentymarketsExportEntityException('The order with the number »' . $this->order['number'] . '« could not be exported (no order id or order status respectively)', 4020);
		}

		// Directly book the incomming payment
		if ($this->order['paymentStatus']['id'] == PlentymarketsConfig::getInstance()->getOrderPaidStatusID(12))
		{
			// May throw an exception
			$IncomingPayment = new PlentymarketsExportEntityOrderIncomingPayment($this->order['id']);
			$IncomingPayment->book();
		}
	}

	/**
	 * Writes an error code into the database
	 *
	 * @param integer $code
	 */
	protected function setError($code)
	{
		Shopware()->Db()
			->prepare('
			UPDATE plenty_order
				SET
					status = ?,
					timestampLastTry = NOW(),
					numberOfTries = numberOfTries + 1
				WHERE shopwareId = ?
		')
			->execute(array(
			$code,
			$this->order['id']
		));
	}

	/**
	 * Writes the plenty order id and the status into the database
	 *
	 * @param integer $plentyOrderID
	 * @param flaot $plentyOrderStatus
	 */
	protected function setSuccess($plentyOrderID, $plentyOrderStatus)
	{
		PlentymarketsLogger::getInstance()->message('Export:Order', 'The sales order with the number  »' . $this->order['number'] . '« has been created in plentymakets (id: ' . $plentyOrderID . ', status: ' . $plentyOrderStatus . ')');

		Shopware()->Db()
			->prepare('
			UPDATE plenty_order
				SET
					status = 2,
					timestampLastTry = NOW(),
					numberOfTries = numberOfTries + 1,
					plentyOrderTimestamp = NOW(),
					plentyOrderId = ?,
					plentyOrderStatus = ?
				WHERE shopwareId = ?
		')
			->execute(array(
			$plentyOrderID,
			$plentyOrderStatus,
			$this->order['id']
		));
	}
}
