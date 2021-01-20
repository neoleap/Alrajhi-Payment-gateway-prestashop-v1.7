<?php


class ArbpgResultModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $cart = $this->context->cart;
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'arbpg') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            die($this->module->l('This payment method is not available.', 'validation'));
        }

        $decrypted = $this->aesDecrypt($_REQUEST["trandata"]);
        $raw = urldecode($decrypted);
        $dataArr = json_decode($raw, true);

        $paymentStatus = $dataArr[0]["result"];

        $this->context->smarty->assign([
            'params' => [
                'status' => $paymentStatus
            ],
        ]);

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer))
            Tools::redirect('index.php?controller=order&step=1');

        $currency = $this->context->currency;
        $total = (float)$cart->getOrderTotal(true, Cart::BOTH);

        if ($paymentStatus && $paymentStatus === "CAPTURED") {
            $this->module->validateOrder($cart->id, Configuration::get('PS_OS_PAYMENT'), $total, $this->module->displayName, NULL, NULL, (int)$currency->id, false, $customer->secure_key);
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
        } else {
            $this->module->validateOrder($cart->id, Configuration::get('PS_OS_ERROR'), $total, $this->module->displayName, NULL, NULL, (int)$currency->id, false, $customer->secure_key);
//            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
            $this->setTemplate('module:arbpg/views/templates/front/payment_return.tpl');
        }
    }

    private function aesDecrypt($code)
    {
        $code = $this->hex2ByteArray(trim($code));
        $code = $this->byteArray2String($code);
        $iv = "PGKEYENCDECIVSPC";
        $key = Configuration::get('ARB_TERMINAL_RESOURCE_KEY');
        $code = base64_encode($code);
        $decrypted = openssl_decrypt($code, 'AES-256-CBC', $key, OPENSSL_ZERO_PADDING,
            $iv);
        return $this->pkcs5_unpad($decrypted);
    }

    private function pkcs5_unpad($text)
    {
        $pad = ord($text{strlen($text) - 1});
        if ($pad > strlen($text)) return false;
        if (strspn($text, chr($pad), strlen($text) - $pad) != $pad) return false;
        return substr($text, 0, -1 * $pad);
    }

    private function hex2ByteArray($hexString)
    {
        $string = hex2bin($hexString);
        return unpack('C*', $string);
    }

    private function byteArray2String($byteArray)
    {
        $chars = array_map("chr", $byteArray);
        return join($chars);
    }

}


/*

1 _PS_OS_CHEQUE_ : waiting for cheque payment
2_PS_OS_PAYMENT_ : payment successful
3_PS_OS_PREPARATION_ : preparing order
4_PS_OS_SHIPPING_ : order shipped
5_PS_OS_DELIVERED_ : order delivered
6_PS_OS_CANCELED_ : order canceled
7_PS_OS_REFUND_ : order refunded
8_PS_OS_ERROR_ : payment error
9_PS_OS_OUTOFSTOCK_ : product out of stock
10_PS_OS_BANKWIRE_ : waiting for bank wire

 */