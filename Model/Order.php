<?php
App::uses('CartAppModel', 'Cart.Model');

/**
 * Order Model
 *
 * @author Florian Krämer
 * @copyright 2014 Florian Krämer
 * @license MIT
 */
class Order extends CartAppModel {

/**
 * Behaviors
 *
 * @var array
 */
	public $actsAs = array(
		'Search.Searchable'
	);

/**
 * Order status
 *
 * @var array
 */
	public $orderStatuses = array(
		'pending',
		'failed',
		'completed',
		'refunded',
		'partial-refunded'
	);

/**
 * belongsTo associations
 *
 * @var array
 */
	public $belongsTo = array(
		'Cart' => array(
			'className' => 'Cart.Cart'
		),
		'User' => array(
			'className' => 'User'
		),
		'BillingAddress' => array(
			'className' => 'Cart.OrderAddress',
		),
		'ShippingAddress' => array(
			'className' => 'Cart.OrderAddress',
		)
	);

/**
 * hasMany associations
 *
 * @var array
 */
	public $hasMany = array(
		'OrderItem' => array(
			'className' => 'Cart.OrderItem'
		)
	);

/**
 * Validation rules
 *
 * @var array
 */
	public $validate = array(
		'total' => array(
			'numeric' => array(
				'rule' => array('numeric'),
				'message' => 'This must be a number'
			)
		),
		'status' => array(
			'notEmpty' => array(
				'rule' => array('notEmpty'),
				'message' => 'The order requires a status'
			)
		),
		'currency' => array(
			'notEmpty' => array(
				'rule' => array('notEmpty'),
				'message' => 'You must select a currency'
			)
		),
		'processor' => array(
			'notEmpty' => array(
				'rule' => array('notEmpty'),
				'message' => 'The order requires a payment processor'
			)
		),
		'cart_snapshot' => array(
			'notEmpty' => array(
				'rule' => array('notEmpty'),
				'message' => 'You must add the cart data to the order'
			)
		)
	);

/**
 * Filters args for search
 *
 * @var array
 */
	public $filterArgs = array(
		array('name' => 'username', 'type' => 'like', 'field' => 'User.username'),
		array('name' => 'email', 'type' => 'like', 'field' => 'User.email'),
		array('name' => 'invoice_number', 'type' => 'like'),
		array('name' => 'total', 'type' => 'value'),
		array('name' => 'created', 'type' => 'like'),
	);

/**
 * beforeSave callback
 *
 * @param  array $options, not used
 * @return boolean
 */
	public function beforeSave($options = array()) {
		$this->_getOrderRecordBeforeSave();
		$this->_serializeCartSnapshot();
		return true;
	}

/**
 * Serializes the cart snapshot data
 *
 * This method is intended to be called only inside Order::beforeSave()
 *
 * @return void
 */
	protected function _serializeCartSnapshot() {
		if (!empty($this->data[$this->alias]['cart_snapshot']) && is_array($this->data[$this->alias]['cart_snapshot'])) {
			$this->data[$this->alias]['cart_snapshot'] = serialize($this->data[$this->alias]['cart_snapshot']);
		}
	}

/**
 * Gets the unchanged order data
 *
 * This method is intended to be called only inside Order::beforeSave()
 *
 * @return void
 */
	protected function _getOrderRecordBeforeSave() {
		if (!empty($this->data[$this->alias][$this->primaryKey])) {
			$this->orderRecordBeforeSave = $this->find('first', array(
				'contain' => array(),
				'conditions' => array(
					$this->alias . '.' . $this->primaryKey = $this->data[$this->alias][$this->primaryKey]
				)
			));
		}
	}

/**
 * Compares changes to the order model fields of the just saved record with the
 * Order::orderRecordBeforeSave and triggers an event if any field was changed
 *
 * This method is intended to be called only inside Order::afterSave()
 *
 * @return void
 */
	protected function _detectOrderChange() {
		if (!empty($this->orderRecordBeforeSave)) {
			$changedFields = array();
			foreach ($this->data[$this->alias] as $field => $value) {
				if (isset($this->orderRecordBeforeSave[$this->alias][$field]) && $this->orderRecordBeforeSave[$this->alias][$field] !== $value) {
					$changedFields[] = $value;
				}
			}
			if (!empty($changedFields)) {
				$this->getEventManager()->dispatch(new CakeEvent('Order.changed', $this, array(
					$this->data,
					$this->orderRecordBeforeSave,
					$changedFields)));
			}
		}
	}

/**
 * afterSave callback
 *
 * @param  boolean $created
 * @param  array $options
 * @return void
 */
	public function afterSave($created, $options = array()) {
		if ($created) {
			if (empty($this->data[$this->alias]['currency'])) {
				$this->data[$this->alias]['currency'] = Configure::read('Cart.defaultCurrency');
			}

			$this->data[$this->alias]['order_number'] = $this->orderNumber($this->data);
			$this->data[$this->alias]['invoice_number'] = $this->invoiceNumber($this->data);
			$this->data[$this->alias][$this->primaryKey] = $this->getLastInsertId();

			$result = $this->save($this->data, array(
				'validate' => false,
				'callbacks' => false));

			$this->data = $result;
			$this->getEventManager()->dispatch(new CakeEvent('Order.created', $this, array($this->data)));
		}

		$this->_detectOrderChange();

		$this->orderRecordBeforeSave = null;
	}

/**
 * afterFind callback
 *
 * @param  array $results
 * @param  bool $primary, not used
 * @return array
 */
	public function afterFind($results, $primary = false) {
		$results = $this->unserializeCartSnapshot($results);
		return $results;
	}

/**
 * Unserializes the data in the cart_snapshot field when it is present
 *
 * @param    array $results
 * @internal param array $results
 * @return   array modified results array
 */
	public function unserializeCartSnapshot($results) {
		if (!empty($results)) {
			foreach ($results as $key => $result) {
				if (isset($result[$this->alias]['cart_snapshot'])) {
					$results[$key][$this->alias]['cart_snapshot'] = unserialize($result[$this->alias]['cart_snapshot']);
				}
			}
		}
		return $results;
	}

/**
 * Returns the data for a user to view an order he made
 *
 * @param  string $orderId Order UUID
 * @param  string $userId User UUId
 * @return array
 * @throws NotFoundException
 */
	public function view($orderId = null, $userId = null) {
		$order = $this->find('first', array(
			'contain' => array(
				'OrderItem',
				'BillingAddress',
				'ShippingAddress'
			),
			'conditions' => array(
				$this->alias . '.' . $this->primaryKey => $orderId,
				$this->alias . '.user_id' => $userId
			)
		));

		if (empty($order)) {
			throw new NotFoundException(__d('cart', 'The order does not exist.'));
		}
		return $order;
	}

/**
 * Returns the data for an order for the admin
 *
 * @param  string $orderId Order UUID
 * @return array
 * @throws NotFoundException
 */
	public function adminView($orderId = null) {
		$order = $this->find('first', array(
			'contain' => array(
				'User',
				//'OrderItem'
			),
			'conditions' => array(
				$this->alias . '.' . $this->primaryKey => $orderId)));

		if (empty($order)) {
			throw new NotFoundException(__d('cart', 'The order does not exist.'));
		}
		return $order;
	}

/**
 * Validate Order
 *
 * Shipping and Billing Address validation if the cart requires shipping
 * by default true, it will get just validated and by this maybe set
 * to invalid, when the cart requires shipping
 *
 * @deprecated
 * @param  array $order
 * @return mixed
 */
	public function validateOrder($order) {
		$validBillingAddress = true;
		$validShippingAddress = true;

		if (isset($order['Cart']['requires_shipping']) && $order['Cart']['requires_shipping'] == 1) {
			$this->ShippingAddress->set($order);
			$validShippingAddress = $this->ShippingAddress->validates();

			if (isset($order['BillingAddress']['same_as_shipping']) && $order['BillingAddress']['same_as_shipping'] == 1) {
				$order['BillingAddress'] = $order['ShippingAddress'];
			} else {
				$this->BillingAddress->set($order);
				$validBillingAddress = $this->BillingAddress->validates();
			}
		}

		$this->set($order);
		$validOrder = $this->validates();

		if (!$validOrder || !$validBillingAddress || !$validShippingAddress) {
			return false;
		}

		return $order;
	}

/**
 * This method will create a new order record and does the validation work for
 * the different cases that might apply before you can issue a new order
 *
 * @deprecated
 * @param    array $cartData
 * @param    string $processorClass
 * @param    string $paymentStatus
 * @internal param $
 * @internal param $
 * @internal param $
 * @return mixed Array with order data on success, false if not
 */
	public function legacyCreateOrder($data, $processorClass, $paymentStatus = 'pending') {
		$order = array(
			$this->alias => array(
				'processor' => $processorClass,
				'payment_status' => $paymentStatus,
				'cart_id' => empty($cartData['Cart']['id']) ? null : $cartData['Cart']['id'],
				'user_id' => empty($cartData['Cart']['user_id']) ? null : $cartData['Cart']['user_id'],
				'cart_snapshot' => $cartData,
				'total' => $cartData['Cart']['total']
			)
		);

		$order = Hash::merge($cartData, $order);

		$this->getEventManager->dispatch(new CakeEvent(
			'Order.beforeCreateOrder',
			$this, array(
				'order' => $order
			)
		));

		$order = $this->validateOrder($order);
		if ($order === false) {
			return false;
		}

		$this->data = null;
		$this->create();
		$result = $this->save($order);

		$orderId = $this->getLastInsertId();
		$result[$this->alias][$this->primaryKey] = $orderId;

		foreach ($order['CartsItem'] as $item) {
			$item['order_id'] = $orderId;
			$this->OrderItem->create();
			$this->OrderItem->save($item);
		}

		if (isset($order['Cart']['requires_shipping']) && $order['Cart']['requires_shipping'] == 1) {
			$order['BillingAddress']['order_id'] = $orderId;
			$order['ShippingAddress']['order_id'] = $orderId;
			if (!isset($order['BillingAddress']['id'])) {
				$this->BillingAddress->create();
			}
			$this->BillingAddress->save($order);
			if (!isset($order['ShippingAddress']['id'])) {
				$this->ShippingAddress->create();
			}
			$this->ShippingAddress->save($order);
		}

		if ($result) {
			$result[$this->alias][$this->primaryKey] = $this->getLastInsertId();
			$this->getEventManager->dispatch(new CakeEvent('Order.created', $this, array($result)));
		}

		$result = Set::merge($result, unserialize($result[$this->alias]['cart_snapshot']));
		return $result;
	}

/**
 * Generates an invoice number
 *
 * @param  array  $data Order data
 * @param  string $date
 * @return string
 */
	public function invoiceNumber($data = array(), $date = null) {
		$Event = new CakeEvent(
			'Order.createInvoiceNumber',
			$this,
			array(
				$data
			)
		);
		$this->getEventManager()->dispatch($Event);
		if ($Event->isStopped()) {
			return $Event->result;
		}

		if (empty($date)) {
			$date = date('Y-m-d');
		}

		$count = $this->find('count', array(
			'contain' => array(),
			'conditions' => array(
				$this->alias . '.created LIKE' => substr($date, 0, -2) . '%'
			)
		));

		if ($count == 1) {
			$increment = $count;
		} else {
			$increment = $count + 1;
		}

		return str_replace('-', '', $date) . '-' . $increment;
	}

/**
 * Order number
 *
 * @param $data, not currently used
 * @return string
 */
	public function orderNumber($data = array()) {
		return $this->find('count');
	}

/**
 * Checks the order data if the shipping address is the same as the billing address
 *
 * @param array
 * @return boolean
 */
	public function shippingIsSameAsBilling($data) {
		if (isset($data['ShippingAddress']['same_as_billing'])) {
			return (bool)$data['ShippingAddress']['same_as_billing'];
		}
		return false;
	}

/**
 * Validates the shipping and billing address
 *
 * @param array $data The order data with the shipping and billing address
 * @return boolean
 */
	public function validateAddresses($data) {
		$sameAsBilling = $this->shippingIsSameAsBilling($data);

		$this->BillingAddress->set($data);
		$validBillingAddress = $this->BillingAddress->validates($data);

		if ($sameAsBilling === true) {
			$validShippingAddress = true;
		} else {
			$this->ShippingAddress->set($data);
			$validShippingAddress = $this->ShippingAddress->validates($data);
		}

		return ($validBillingAddress && $validShippingAddress);
	}

/**
 * saveAddresses
 *
 * @param $data
 * @return array
 */
	public function saveAddresses($data) {
		$sameAsBilling = $this->shippingIsSameAsBilling($data);

		$billingAddressId = $this->BillingAddress->findDuplicate();
		if ($billingAddressId === false) {
			$this->BillingAddress->create();
			$this->BillingAddress->save($data, array('validate' => false));
			$billingAddressId = $this->BillingAddress->getLastInsertId();
		}

		if ($sameAsBilling === false) {
			$shippingAddressId = $this->ShippingAddress->findDuplicate();
			if ($shippingAddressId === false) {
				$this->ShippingAddress->create();
				$this->ShippingAddress->save($data, array('validate' => false));
				$shippingAddressId = $this->BillingAddress->getLastInsertId();
			}
		} else {
			$shippingAddressId = $billingAddressId;
			$data['ShippingAddress'] = $data['BillingAddress'];
		}

		$data[$this->alias]['shipping_address_id'] = $shippingAddressId;
		$data[$this->alias]['billing_address_id'] = $billingAddressId;

		return $data;
	}

/**
 * Saves all the items for the order in the persistent order_items table
 *
 * @param $orderId
 * @param array $data
 * @return void
 */
	public function saveItems($orderId, $data) {
		foreach ($data['CartsItem'] as $item) {
			$item['order_id'] = $orderId;
			$this->OrderItem->create();
			$this->OrderItem->save($item);
		}
	}

/**
 * This method validates all data, the order and all associated data
 *
 * All associated data is validates as well and not skipped on the first
 * false result to get the errors displayed in the form.
 *
 * @param array $data
 * @param array $options
 * @return boolean
 */
	public function beforeOrderValidation($data, $options = array()) {
		$this->set($data);
		$validOrder = $this->validates();
		$validCreditCard = $this->validateCreditCard($data);
		$validAddresses = $this->validateAddresses($data);

		return ($validOrder && $validCreditCard && $validAddresses);
	}

}
