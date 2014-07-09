<?php
/**
 *  @copyright Copyright (c) 2014 DodatkiJoomla.pl
 *  @license GNU/GPL v2
 */
if (!defined('_VALID_MOS') && !defined('_JEXEC')) die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');


// jeżeli klasa vmPSPlugin nie istnieje, dołącz
if (!class_exists('vmPSPlugin'))
{
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}
	
class plgVmPaymentPrzelewy_24 extends vmPSPlugin 
{

    // instancja klasy
    public static $_this = false;

	// konstruktor
    function __construct(& $subject, $config) 
	{
		// konstruktor kl. nadrzędnej
		parent::__construct($subject, $config);
		
		$this->_loggable = true;
		
	
		// dowalić to poniżej - to zapisuje wartości z xml'a do kol. payment_params tabeli #__virtuemart_paymentmethods	
		$this->tableFields = array_keys($this->getTableSQLFields());
		$varsToPush = array(
		'payment_logos' => array('', 'char'),
		'p24_id_sprzedawcy' => array('', 'string'),
		'p24_klucz_crc' => array('', 'string'),
		'status_pending' => array('', 'string'),
		'status_success' => array('', 'string'),
		'status_canceled' => array('', 'string'),		
		'tax_id' => array(0, 'int'),
		'autoredirect' => array(1, 'int'),
		'payment_image' => array('', 'string'),
		'checkout_text' => array('', 'string'),
		'p24_autoupdate_url' => array('', 'string'),
		'tryb_testowy' => array(0, 'int'),
		'powiadomienia' => array(1, 'int'),
		'cost_per_transaction' => array(0, 'double'),
		'cost_percent_total' => array(0, 'double')
	    );
		
		// poprawka - błąd z typem kolumny :/
		$db = JFactory::getDBO();
		$q = "SHOW COLUMNS FROM #__virtuemart_payment_plg_przelewy_24 WHERE Field='id' AND Type LIKE 'tinyint%'";		
		$db->setQuery($q);
		$result = $db->loadObject();
		if(!empty($result))
		{
			$q = "ALTER TABLE #__virtuemart_payment_plg_przelewy_24 CHANGE id id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT";
			$db->setQuery($q);
			$db->query();
		}
		
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}
	
	function getTableSQLFields() 
	{
		$SQLfields = array(
			'id' => ' int(11) UNSIGNED NOT NULL AUTO_INCREMENT ',
			'virtuemart_order_id' => ' int(11) UNSIGNED DEFAULT NULL',
			'order_number' => ' char(32) DEFAULT NULL',
			'virtuemart_paymentmethod_id' => ' mediumint(1) UNSIGNED DEFAULT NULL',
			'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
			'tax_id' => 'int(11) DEFAULT NULL',
			'kwota_calkowita_PLN' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
			'p24_session_id' => 'varchar(32) ',
			'p24_id_sprzedawcy' => 'int(11) DEFAULT NULL',
			'p24_kwota' => 'int(11) DEFAULT NULL',
			'p24_crc' => 'varchar(32) '
			
	);
		return $SQLfields;
    }
	
	// potwierdzenie zamówienia funkcja 
	
	function plgVmPotwierdzeniePrzelewy24($cart, $order, $auto_redirect = false)
	{
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return null; // Inna metoda została wybrana, nie rób nic.
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		

		
		if (!class_exists('VirtueMartModelOrders'))
		{
			require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		}
		
		// konwersja do PLN
		$this->getPaymentCurrency($method);
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
		$db = &JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
		
		$kwota_calkowita_PLN = round($paymentCurrency->convertCurrencyTo(114, $order['details']['BT']->order_total, false), 2); // konwertuj do PLN, 114 - id złotówki
	
		// zmienne
		
		$kwota_grosze = round($kwota_calkowita_PLN*100,0);
		$zamowienie = $order['details']['BT'];
		$session_id = md5($zamowienie->order_number.'|'.time());
		$url_ok = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $zamowienie->virtuemart_paymentmethod_id);
		$url_error = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $zamowienie->order_number . '&pm=' . $zamowienie->virtuemart_paymentmethod_id);
		
		// jeżeli jest ustawiony klucz CRC i ma 16 znaków - go!
		$p24_crc = NULL;
		if(!empty($method->p24_klucz_crc) && strlen($method->p24_klucz_crc)==16)
		{
			$tab_crc = array(
			$session_id,
			$method->p24_id_sprzedawcy,
			$kwota_grosze,
			$method->p24_klucz_crc
			);
			$p24_crc = md5(implode("|",	$tab_crc));
		}
		
		$this->_virtuemart_paymentmethod_id = $zamowienie->virtuemart_paymentmethod_id;
		$dbValues['order_number'] = $zamowienie->order_number;
		$dbValues['payment_name'] = $this->renderPluginName($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
		$dbValues['kwota_calkowita_PLN'] = $kwota_calkowita_PLN;
		$dbValues['tax_id'] = $method->tax_id;
		
		// wartości Przelewy 24
		$dbValues['p24_session_id'] = $session_id;
		$dbValues['p24_id_sprzedawcy'] = $method->p24_id_sprzedawcy;
		$dbValues['p24_kwota'] = $kwota_grosze;
		$dbValues['p24_crc'] = $p24_crc;
		
		// zapisz do bazy
		$this->storePSPluginInternalData($dbValues);
		
		// action url
		$url = "https://secure.przelewy24.pl/index.php";
		if($method->tryb_testowy==1)
		{
			$url = "https://sandbox.przelewy24.pl/index.php";
		}
		
		// zawartośc HTML na podstronie potwierdzenia zamówienia //Numer zamówienia: '.$order['details']['BT']->order_number.'
		$html = '
		<div style="text-align: center; width: 100%; ">
		<form action="'.$url.'" method="POST" class="form" id="platnosc_przelewy24"> 
		  <input type="hidden" name="p24_session_id" value="'.$session_id.'" /> 
		  <input type="hidden" name="p24_id_sprzedawcy" value="'.$method->p24_id_sprzedawcy.'" /> 
		  <input type="hidden" name="p24_kwota" value="'.$kwota_grosze.'" />
		  <input type="hidden" name="encoding" value="utf-8" /> ';
		  
		  
		  $html .= '<input type="hidden" name="p24_opis" value="Numer zamówienia: '.$order['details']['BT']->order_number.'" />';
		  
		  $html .= '<input type="hidden" name="p24_klient" value="'.$zamowienie->first_name.' '.$zamowienie->last_name.'"  /> 
		  <input type="hidden" name="p24_adres" value="'.$zamowienie->address_1.'" /> 
		  <input type="hidden" name="p24_kod" value="'.$zamowienie->zip.'" /> 
		  <input type="hidden" name="p24_miasto" value="'.$zamowienie->city.'" /> 
		  <input type="hidden" name="p24_kraj" value="PL" /> 
		  <input type="hidden" name="p24_email" value="'.$zamowienie->email.'" /> 
		  <input type="hidden" name="p24_language" value="pl" /> 
		  <input type="hidden" name="p24_return_url_ok" value="'.$url_ok.'" /> 
		  <input type="hidden" name="p24_return_url_error" value="'.$url_error.'" /> ';
		if(!empty($p24_crc))
		{
			$html .= '<input type="hidden" name="p24_crc" value="'.$p24_crc.'" /> ';
		}
		
		// button
		if(file_exists(JPATH_BASE.DS.'images'.DS.'stories'.DS.'virtuemart'.DS.'payment'.DS.$method->payment_image))
		{
			$pic = getimagesize(JPATH_BASE.DS.'images/stories/virtuemart/payment/'.$method->payment_image);
			$html .= '<input name="submit_send" value="" type="submit" style="border: 0; background: url(\''.JURI::root().'images/stories/virtuemart/payment/'.$method->payment_image.'\'); width: '.$pic[0].'px; height: '.$pic[1].'px; cursor: pointer;" /> ';
		}
		else
		{
			$html .= '<input name="submit_send" value="Zapłać z Przelewy24" type="submit"  style="width: 140px; height: 45px;" /> ';
		}
		
		$html .= '		  
		</form>		
		<p style="text-align: center; width: 100%; ">'.$method->checkout_text.'</p>
		</div>
		';
		
		// automatyczne przerzucenie do płatności
		if($method->autoredirect && $auto_redirect)
		{
			$html .= '
			<script type="text/javascript">
				window.addEvent("load", function() { 
					document.getElementById("platnosc_przelewy24").submit(); 
					});
			</script>';
		}
		
		return $html;
	}
	
	function plgVmConfirmedOrder($cart, $order)
	{
		// jeżeli nie zwraca $html - wyrzuc false
		if (!($html = $this->plgVmPotwierdzeniePrzelewy24($cart, $order, true))) {
			return false; 
		}
		
		// nazwa płatnosci - zmiana dla Joomla 2.5 !!!
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) 
		{
			return null;
		}
		$nazwa_platnosci = $this->renderPluginName($method);
		
		// tutaj w vm 2.0.2 trzeba dodać status na końcu, zeby się nie wywalało
		return $this->processConfirmedOrderPaymentResponse(1, $cart, $order, $html, $nazwa_platnosci, $method->status_pending);
	}
	
	// zdarzenie po otrzymaniu poprawnego callbacku z systemu płatności
	function plgVmOnPaymentResponseReceived(&$html) 
	{
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		
		$payment_data = JRequest::get('post');
		if (count($payment_data)==0)
		{
			JError::raiseWarning( 100, '<b>Wystąpił błąd:</b> Dane zwrotne z systemu Przelewy24 nie zostały przekazane.' );
			return false;
		}
		
		//
		// potwierdź płatność
		//
		
		// sprawdź czy jest cURL
		if(function_exists('curl_init') )
		{
			// pobierz płatność z bazy
			$db = &JFactory::getDBO();
			$q = 'SELECT p24.*, ord.order_status FROM '.$this->_tablename.' as p24 JOIN `#__virtuemart_orders` as ord using(virtuemart_order_id) WHERE p24.p24_session_id="' .$payment_data['p24_session_id']. '" ';			

			$db->setQuery($q);
			$payment_db = $db->loadObject();
		
			if(!empty($payment_db) && $payment_data['p24_kwota']==$payment_db->p24_kwota && $payment_data['p24_id_sprzedawcy']==$payment_db->p24_id_sprzedawcy )
			{
				if($payment_db->order_status!='C' && $payment_db->order_status!='X')
				{
					$P = array();
					$url = "https://secure.przelewy24.pl/transakcja.php"; 
					if($method->tryb_testowy==1)
					{
						$url = "https://sandbox.przelewy24.pl/transakcja.php";
					}
					$P[] = "p24_id_sprzedawcy=".$payment_data['p24_id_sprzedawcy'];
					$P[] = "p24_session_id=".$payment_data['p24_session_id']; 
					$P[] = "p24_order_id=".$payment_data['p24_order_id']; 
					$P[] = "p24_kwota=".$payment_data['p24_kwota']; 
					// crc - $method->p24_klucz_crc;
					if(!empty($payment_db->p24_crc))
					{
						$P[] = "p24_crc=".md5($payment_data['p24_session_id']."|". $payment_data['p24_order_id']."|".$payment_data['p24_kwota']."|".$method->p24_klucz_crc); 
					}

					$user_agent = "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)"; 
					$ch = curl_init(); 
					curl_setopt($ch, CURLOPT_POST,1); 
					if(count($P)) { curl_setopt($ch, CURLOPT_POSTFIELDS,join("&",$P)); } 
					curl_setopt($ch, CURLOPT_URL,$url); 
					curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,  2); 
					curl_setopt($ch, CURLOPT_USERAGENT, $user_agent); 
					curl_setopt($ch, CURLOPT_RETURNTRANSFER,1); 
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
					$result=curl_exec ($ch);

					if(strstr($result,'TRUE')!=false)
					{
						$virtuemart_order_id = $payment_db->virtuemart_order_id;

						$message = 'Płatność została potwierdzona.';
						JFactory::getApplication()->enqueueMessage( 'Płatność została potwierdzona' );	
						
						// update statusu
						if(($status = $this->nowyStatus($virtuemart_order_id,$method->status_success, $message, $method->powiadomienia))==false)
						{
							JError::raiseWarning( 100, '<b>Wystąpił błąd:</b> Brak takiego zamówienia.' );
							return false;
						}
						else
						{
							
							return true;
						}
					}
					else
					{
						// last modify płatności w BD
						$db = &JFactory::getDBO();
						$q = 'UPDATE '.$this->_tablename.' SET modified_on=NOW() WHERE virtuemart_order_id='.$virtuemart_order_id.';   ';

						$db->setQuery($q);
						$db->query($q);
						
						$a = explode("\r\n",$result);
						$err_msg = "Wystąpił nieznany błąd.";
						if(count($err_msg)>3 && $this->getBledyP24($a[2])!=false)
						{
							$err_msg = $this->getBledyP24($a[2]);
						}
						JError::raiseWarning( 100, '<b>Wystąpił błąd:</b> '.$err_msg);
						return false;
					}
				}
				else
				{
					JError::raiseNotice( 100, '<b>Powiadomienie:</b> Zamówienie zostało już potwierdzone lub anulowane.');
					return false;
				}
				
			}
			else
			{
				JError::raiseWarning( 100, '<b>Wystąpił błąd:</b> Błędny identyfikator transakcji.');
				return false;
			}
			
		}
		else
		{

				JError::raiseWarning( 100, '<b>Wystąpił błąd:</b> Serwer nie obsługuje biblioteki cURL, nie można automatycznie zweryfikować płatności.<br> Skontaktuj się ze swoim administratorem, aby włączyć obsługę biblioteki cURL na serwerze.');
				return false;			
				
		}


	}

	// zdarzenie po błędnym callbacku z systemu
	
	function plgVmOnUserPaymentCancel() 
	{
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		
		$payment_data = JRequest::get('post');
		if (count($payment_data)==0)
		{
			JError::raiseWarning( 100, '<b>Wystąpił błąd:</b> Dane zwrotne z systemu Przelewy24 nie zostały przekazane.' );
			return false;
		}
		
		// pobierz płatność i zamówienie z bazy
		
		$db = &JFactory::getDBO();
		$q = 'SELECT p24.*, ord.order_status FROM '.$this->_tablename.' as p24 JOIN `#__virtuemart_orders` as ord using(virtuemart_order_id) WHERE p24.p24_session_id="' .$payment_data['p24_session_id']. '" ';
		$db->setQuery($q);

		$payment_db = $db->loadObject();
		
		if(empty($payment_db))
		{
			JError::raiseWarning( 100, '<b>Wystąpił błąd:</b> Brak takiego zamówienia.' );
			return false;
		}
		else
		{
			$virtuemart_order_id = $payment_db->virtuemart_order_id;
			
			if($payment_db->order_status=='C' || $payment_db->order_status=='X')
			{
				JError::raiseNotice( 100, '<b>Powiadomienie:</b> Zamówienie zostało już potwierdzone lub anulowane.');
				return false;
			}

			
			$blad = $payment_data['p24_error_code'];
			$err_msg = "Wystąpił nieznany błąd.";
			if($this->getBledyP24($blad)!=false)
			{
				$err_msg = $this->getBledyP24($blad);
			}
			JError::raiseWarning( 100, '<b>Wystąpił błąd płatności:</b> '.$err_msg);
			
			if(($status = $this->nowyStatus($virtuemart_order_id,$method->status_canceled,'<b>Wystąpił błąd płatności:</b> '.$err_msg, $method->powiadomienia))==false)
			{
				JError::raiseWarning( 100, '<b>Wystąpił błąd:</b> Brak takiego zamówienia.' );
				return false;
			}
			else
			{
				return true;
			}

		}
		
	}
	
	// Kody błędów w P24
	
	function getBledyP24($kod_bledu)
	{
		$blad = array(
		"err00" => "Nieprawidłowe wywołanie skryptu",
		"err01" => "Nie uzyskano od sklepu potwierdzenia odebrania odpowiedzi autoryzacyjnej",
		"err02" => "Nie uzyskano odpowiedzi autoryzacyjnej",
		"err03" => "To zapytanie było już przetwarzane",
		"err04" => "Zapytanie autoryzacyjne niekompletne lub niepoprawne",
		"err05" => "Nie udało się odczytać konfiguracji sklepu internetowego",
		"err06" => "Nieudany zapis zapytania autoryzacyjnego",
		"err07" => "Inna osoba dokonuje płatności",
		"err08" => "Nieustalony status połączenia ze sklepem.",
		"err09" => "Przekroczono dozwoloną liczbę poprawek danych.",
		"err10" => "Nieprawidłowa kwota transakcji!",
		"err49" => "Zbyt wysoki wynik oceny ryzyka transakcji przeprowadzonej przez PolCard.",
		"err51" => "Nieprawidłowe wywołanie strony",
		"err52" => "Błędna informacja zwrotna o sesji!",
		"err53" => "Błąd transakcji !",
		"err54" => "Niezgodność kwoty transakcji !",
		"err55" => "Nieprawidłowy kod odpowiedzi !",
		"err56" => "Nieprawidłowa karta",
		"err57" => "Niezgodność flagi TEST !",
		"err58" => "Nieprawidłowy numer sekwencji !",
		"err101" => "Błąd wywołania strony",
		"err102" => "Minął czas na dokonanie transakcji",
		"err103" => "Nieprawidłowa kwota przelewu",
		"err104" => "Transakcja oczekuje na potwierdzenie.",
		"err105" => "Transakcja dokonana po dopuszczalnym czasie",
		"err161" => "Żądanie transakcji przerwane przez użytkownika",
		"err162" => "Żądanie transakcji przerwane przez użytkownika"
		);

		if(array_key_exists($kod_bledu, $blad))
		{	
			return $blad[$kod_bledu];
		}
		else
		{
			return false;
		}
	}
	
	// wyświetl dane płatności dla zamówienia (backend)
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) 
	{
		if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
			return null; // Another method was selected, do nothing
		}

		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
			vmWarn(500, $q . " " . $db->getErrorMsg());
			return '';
		}
		$this->getPaymentCurrency($paymentTable);

		$html = '<table class="adminlist">' . "\n";
		$html .=$this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_NAME', $paymentTable->payment_name);
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', round($paymentTable->kwota_calkowita_PLN, 2).' PLN');
		$html .= '</table>' . "\n";
		return $html;
    }
	
	
	// moja funkcja nowego statusu
	function nowyStatus($virtuemart_order_id, $nowy_status, $notatka = "",  $wyslij_powiadomienie=1)
	{
			if (!class_exists('VirtueMartModelOrders'))
			{
				require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			}
			
			// załadowanie języka dla templatey zmiany statusu zam. z admina!
			$lang = &JFactory::getLanguage();		
			$lang->load('com_virtuemart',JPATH_ADMINISTRATOR);
			
			$modelOrder = VmModel::getModel('orders');
			$zamowienie = $modelOrder->getOrder($virtuemart_order_id);
			if(empty($zamowienie))
			{
				return false;
			}
			
			$order['order_status'] = $nowy_status;
			$order['virtuemart_order_id'] = $virtuemart_order_id;
			$order['customer_notified'] = $wyslij_powiadomienie;
			$order['comments'] = $notatka;
			$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);

			// last modify + lock płatności w BD
			
			$db = &JFactory::getDBO();
			// sql'e zależne od nowego statusu
			
			if($nowy_status=="C" || $nowy_status=="X")
			{
				$q = 'UPDATE '.$this->_tablename.' SET modified_on=NOW(), locked_on=NOW() WHERE virtuemart_order_id='.$virtuemart_order_id.';   ';		
			}
			else
			{
				$q = 'UPDATE '.$this->_tablename.' SET modified_on=NOW() WHERE virtuemart_order_id='.$virtuemart_order_id.';   ';
			}

			$db->setQuery($q);
			$wynik = $db->query($q);
			
			if(empty($wynik))
			{
				return false;
			}

			$message = 'Status zamówienia zmienił się.';


			
			return $message;
	}
	
	
	// sprawdź czy płatność spełnia wymagania
	protected function checkConditions($cart, $method, $cart_prices) 
	{
		return true;
	}
	
	
	/*
	*
	*	RESZTA METOD
	*
	*/
	
	
	protected function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Payment Przelewy24 Table');
    }
	
	// utwórz opcjonalnie tabelę płatności, zapisz dane z xml'a itp.
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) 
	{
		return $this->onStoreInstallPluginTable($jplugin_id);
    }
	
	// zdarzenie po wyborze płatności (front)
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) 
	{
		return $this->OnSelectCheck($cart);
    }
		
	// zdarzenie wywoływane podczas listowania płatności
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) 
	{
		return $this->displayListFE($cart, $selected, $htmlIn);
    }
	
	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) 
	{
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) 
	{
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		 $this->getPaymentCurrency($method);

		$paymentCurrencyId = $method->payment_currency;
    }
	
	// sprawdza ile pluginów płatności jest dostepnych, jeśli tylko jeden, użytkownik nie ma wyboru
	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) 
	{
		return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }
	
	// zdarzenie wywoływane podczas przeglądania szczegółów zamówienia (front)
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) 
	{	
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
	
	 // funkcja wywołująca stricte zawartość komórki payment w szczegółach zamówienia (front - konto usera)
	 function onShowOrderFE($virtuemart_order_id, $virtuemart_method_id, &$method_info)
	 {
	 	if (!($this->selectedThisByMethodId($virtuemart_method_id))) {
			return null;
		}
	 
		// zmiany w wersji 1.1 (1.5 dla p24), ograniczenie generowania się dodatkowego fomrularza, jeśli klient nie opłacił jeszcze zamówienia, tylko do szczegółów produktu
		// dodatkowo w zależności od serwera, tworzenie faktury w PDF głupieje czasami przy obrazkach dla płatności 
		if(isset($_REQUEST['view']) && $_REQUEST['view']=='orders' && isset($_REQUEST['layout']) && $_REQUEST['layout']=='details')
		{
			// wywołaj cały formularz
			if (!class_exists('VirtueMartModelOrders'))
			{
				require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			}
			if (!class_exists('VirtueMartCart'))
			{
				require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
			}	
			if (!class_exists('CurrencyDisplay'))
			{
				require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
			}
			$modelOrder = new VirtueMartModelOrders();
			$cart = VirtueMartCart::getCart();
			$order = $modelOrder->getOrder($virtuemart_order_id);

			
			if (!($html = $this->plgVmPotwierdzeniePrzelewy24($cart, $order)) || $order['details']['BT']->order_status=='C' || $order['details']['BT']->order_status=='U' ) 
			{			
				$method_info = $this->getOrderMethodNamebyOrderId($virtuemart_order_id);
			}
			else
			{
				$method_info = $html;
			}
		}
		else
		{
			$method_info = 'Przelewy24';
		}
	 }
	 
	// pobranie nazwy płatności z bazy 
	function getOrderMethodNamebyOrderId ($virtuemart_order_id) {

		$db = JFactory::getDBO ();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id.  ' ORDER BY id DESC LIMIT 1 ';
		$db->setQuery ($q);
		if (!($pluginInfo = $db->loadObject ())) {
			vmWarn ('Attention, ' . $this->_tablename . ' has not any entry for the order ' . $db->getErrorMsg ());
			return NULL;
		}
		$idName = $this->_psType . '_name';

		return $pluginInfo->$idName;
	}
	
	 /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id  method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */

	// wymagane aby zapis XML'a do BD działał
	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
	return $this->onShowOrderPrint($order_number, $method_id);
    }

	// wymagane aby zapis XML'a do BD działał
    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
	
		// nadpisujemy parametr , aby edycja nic mu nie robiła!
		$virtuemart_paymentmethod_id = $_GET['cid'][0];
		$link = 'p24_autoupdate_url="'.JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm='.$virtuemart_paymentmethod_id.'"|';			
		$data->payment_params .= $link;
		
		return $this->declarePluginParams('payment', $name, $id, $data);
    }

	// wymagane aby zapis XML'a do BD działał
    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
	return $this->setOnTablePluginParams($name, $id, $table);
    }
}