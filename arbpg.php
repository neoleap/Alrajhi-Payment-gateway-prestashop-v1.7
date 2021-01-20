<?php
/*
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
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2015 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Arbpg extends PaymentModule
{
    const ARB_HOSTED_ENDPOINT = 'https://securepayments.alrajhibank.com.sa/pg/payment/hosted.htm';
    const ARB_PAYMENT_ENDPOINT = 'https://securepayments.alrajhibank.com.sa/pg/paymentpage.htm?PaymentID=';

    protected $_html = '';
    protected $_postErrors = array();

    public $details;
    public $owner;
    public $address;
    public $extra_mail_vars;

    private $resultUrl;
    private $transportalId;
    private $transportalPassword;
    private $resourceKey;
    private $paymentId;

    public function __construct()
    {
        $this->name = 'arbpg';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.1';
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->author = 'Al-Rajhi | Wjl';
        $this->controllers = array('validation');
        $this->is_eu_compatible = 1;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('Al-Rajhi Bank Payment Gateway');
        $this->description = $this->l('Allow customers to pay with credit cards safely using ARB payment gateway');

        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }

        $this->resultUrl = _PS_BASE_URL_ . __PS_BASE_URI__ . 'module/' . $this->name . '/result';
        $this->transportalId = Configuration::get('ARB_TRANSPORTAL_ID');
        $this->transportalPassword = Configuration::get('ARB_TRANSPORTAL_PASSWORD');
        $this->resourceKey = Configuration::get('ARB_TERMINAL_RESOURCE_KEY');
        $this->paymentId = $this->getPaymentId();
    }

    private function initConfigParams()
    {
        Configuration::set('ARB_TRANSPORTAL_ID', "");
        Configuration::set('ARB_TRANSPORTAL_PASSWORD', "");
        Configuration::set('ARB_TERMINAL_RESOURCE_KEY', "");
        Configuration::set('ARB_TRACK_COUNTER', 100000);
    }

    public function install()
    {
        if (!parent::install() || !$this->registerHook('paymentOptions') || !$this->registerHook('paymentReturn')) {
            return false;
        }
        $this->initConfigParams();
        return true;
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        if (!$this->checkCurrency($params['cart'])) {
            return;
        }

        $payment_options = [
            $this->getExternalPaymentOption(),
        ];

        return $payment_options;
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getExternalPaymentOption()
    {

        $externalOption = new PaymentOption();
        $externalOption
            ->setCallToActionText($this->l('Credit Card'))
            ->setAdditionalInformation($this->context->smarty->fetch('module:arbpg/views/templates/front/payment_infos.tpl'))
//            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/arb-payment.png'))
            ->setAction(self::ARB_PAYMENT_ENDPOINT . $this->paymentId);

        return $externalOption;
    }

    /**
     * Configuration function
     */
    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit' . $this->name)) {
            $id = strval(Tools::getValue('ARB_TRANSPORTAL_ID'));
            $password = strval(Tools::getValue('ARB_TRANSPORTAL_PASSWORD'));
            $resourceKey = strval(Tools::getValue('ARB_TERMINAL_RESOURCE_KEY'));

            if (
                !$id ||
                empty($id) ||
                !Validate::isGenericName($id) ||
                !$password ||
                empty($password) ||
                !Validate::isGenericName($password) ||
                !$resourceKey ||
                empty($resourceKey) ||
                !Validate::isGenericName($resourceKey)
            ) {
                $output .= $this->displayError($this->l('Invalid Configurations values'));
            } else {
                Configuration::updateValue('ARB_TRANSPORTAL_ID', $id);
                Configuration::updateValue('ARB_TRANSPORTAL_PASSWORD', $password);
                Configuration::updateValue('ARB_TERMINAL_RESOURCE_KEY', $resourceKey);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        return $output . $this->displayForm();
    }

    private function displayForm()
    {
        // Get default language
        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');

        // Init Fields form array
        $fieldsForm[0]['form'] = [
            'legend' => [
                'title' => $this->l('Settings'),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Transportal ID'),
                    'name' => 'ARB_TRANSPORTAL_ID',
                    'size' => 20,
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Transportal Password'),
                    'name' => 'ARB_TRANSPORTAL_PASSWORD',
                    'size' => 20,
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Terminal Resource Key'),
                    'name' => 'ARB_TERMINAL_RESOURCE_KEY',
                    'size' => 32,
                    'required' => true
                ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            ]
        ];

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Language
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = [
            'save' => [
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name .
                    '&token=' . Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            ]
        ];

        // Load current value
        $helper->fields_value['ARB_TRANSPORTAL_ID'] = Configuration::get('ARB_TRANSPORTAL_ID');
        $helper->fields_value['ARB_TRANSPORTAL_PASSWORD'] = Configuration::get('ARB_TRANSPORTAL_PASSWORD');
        $helper->fields_value['ARB_TERMINAL_RESOURCE_KEY'] = Configuration::get('ARB_TERMINAL_RESOURCE_KEY');

        return $helper->generateForm($fieldsForm);
    }

    private function getPaymentId()
    {
        $plainData = $this->getRequestData();
        $wrappedData = $this->wrapData($plainData);


        $encData = [
            "id" => $this->transportalId,
            "trandata" => $this->aesEncrypt($wrappedData),
            "errorURL" => $this->resultUrl,
            "responseURL" => $this->resultUrl
        ];
        $wrappedData = $this->wrapData(json_encode($encData, JSON_UNESCAPED_SLASHES));

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => self::ARB_HOSTED_ENDPOINT,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $wrappedData,

            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Accept-Language: application/json',
                'Content-Type: application/json',
                'Cookie: BIGipServer~UAT-DMZ~rpyuatiwb-IN-https.app~rpyuatiwb-IN-https_pool=rd10o00000000000000000000ffffac1500e3o9999; JSESSIONID=V2zwlYnff4FJdDgFfeOmZOxHQbavnaFqs6sDy76AZIU8M3nasO3L!-1975904906'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

        // parse response and get id
        $data = json_decode($response, true)[0];
        if ($data["status"] == "1") {
            $id = explode(":", $data["result"])[0];
            return $id;
        } else {
            // handle error either refresh on contact merchant
            return -1;
        }
    }

    private function aesEncrypt($plainData)
    {
        $key = $this->resourceKey;
        $iv = "PGKEYENCDECIVSPC";
        $str = $this->pkcs5_pad($plainData);
        $encrypted = openssl_encrypt($str, "aes-256-cbc", $key, OPENSSL_ZERO_PADDING, $iv);
        $encrypted = base64_decode($encrypted);
        $encrypted = unpack('C*', ($encrypted));
        $encrypted = $this->byteArray2Hex($encrypted);
        $encrypted = urlencode($encrypted);
        return $encrypted;
    }

    private function pkcs5_pad($text, $blocksize = 16)
    {
        $pad = $blocksize - (strlen($text) % $blocksize);
        return $text . str_repeat(chr($pad), $pad);
    }

    private function byteArray2Hex($byteArray)
    {
        $chars = array_map("chr", $byteArray);
        $bin = join($chars);
        return bin2hex($bin);
    }

    private function byteArray2String($byteArray)
    {
        $chars = array_map("chr", $byteArray);
        return join($chars);
    }

    private function getRequestData()
    {
        $total = null;
        try {
            if (isset($this->context->cart)) {
                $total = $this->context->cart->getOrderTotal();
            }
        } catch (Exception $exception) {
            $total = null;
        }

//        $trackId = Configuration::get('ARB_TRACK_COUNTER');
        $trackId = rand(100000, 100000000);

        $data = [
            "id" => $this->transportalId,
            "password" => $this->transportalPassword,
            "action" => "1",
            "currencyCode" => "682",
            "errorURL" => $this->resultUrl,
            "responseURL" => $this->resultUrl,
            "trackId" => $trackId,
            "amt" => $total
        ];

//        Configuration::updateValue('ARB_TRACK_COUNTER', (int)Configuration::get('ARB_TRACK_COUNTER') + 1);

        $data = json_encode($data, JSON_UNESCAPED_SLASHES);

        return $data;
    }

    private function wrapData($data)
    {
        $data = <<<EOT
[$data]
EOT;
        return $data;
    }
}