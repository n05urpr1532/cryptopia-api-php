<?php

namespace Cryptopia;

class Api
{
    private $privateKey;
    private $publicKey;

    public function __construct($priv, $pub)
    {
        $this->privateKey = $priv;
        $this->publicKey = $pub;
    }

    private function apiCall($method, array $req = [])
    {
        $public_set = ["GetCurrencies", "GetTradePairs", "GetMarkets", "GetMarket", "GetMarketHistory", "GetMarketOrders"];
        $private_set = ["GetBalance", "GetDepositAddress", "GetOpenOrders", "GetTradeHistory", "GetTransactions", "SubmitTrade", "CancelTrade", "SubmitTip"];
        static $ch = null;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; Cryptopia.co.nz API PHP client; FreeBSD; PHP/'.phpversion().')');
        if (in_array($method, $public_set)) {
            $url = "https://www.cryptopia.co.nz/api/" . $method;
            if ($req) {
                foreach ($req as $r) {
                    $url = $url . '/' . $r;
                }
            }
            curl_setopt($ch, CURLOPT_URL, $url);
        } elseif (in_array($method, $private_set)) {
            $url = "https://www.cryptopia.co.nz/Api/" . $method;
            $nonce = bin2hex(random_bytes(32));
            $post_data = json_encode($req);
            $m = md5($post_data, true);
            $requestContentBase64String = base64_encode($m);
            $signature = $this->publicKey . "POST" . strtolower(urlencode($url)) . $nonce . $requestContentBase64String;
            $hmacsignature = base64_encode(hash_hmac("sha256", $signature, base64_decode($this->privateKey), true));
            $header_value = "amx " . $this->publicKey . ":" . $hmacsignature . ":" . $nonce;
            $headers = ["Content-Type: application/json; charset=utf-8", "Authorization: $header_value"];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req));
        }
        // run the query
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true); // Do Not Cache
        $res = curl_exec($ch);
        if ($res === false) throw new \Exception('Could not get reply: ' . curl_error($ch));

        return $res;
    }

    /**
     * "TradeId": 23467,
     * "TradePairId": 100,
     * "Market": "DOT/BTC",
     * "Type": "Buy",
     * "Rate": 0.00000034,
     * "Amount": 145.98000000,
     * "Total": "0.00004963",
     * "Fee": "0.98760000",
     * "TimeStamp":"2014-12-07T20:04:05.3947572"
     */
    public function getTradeHistory($tradePairId) : array
    {
        $result = json_decode($this->apiCall("GetTradeHistory", ['TradePairId' => $tradePairId]), true);

        if (!$result['Success']) {
            throw new \Exception("Can't get trade history, Error: " . $result['Error']);
        }

        return $result['Data'];
    }


    /**
     * "TradePairId" => 1261
     * "Label" => "$$$/BTC"
     * "AskPrice" => 2.7E-7
     * "BidPrice" => 2.5E-7
     * "Low" => 2.3E-7
     * "High" => 2.9E-7
     * "Volume" => 810030.12268635
     * "LastPrice" => 2.5E-7
     * "BuyVolume" => 101136786.26353
     * "SellVolume" => 20763557.173173
     * "Change" => 4.17
     * "Open" => 2.4E-7
     * "Close" => 2.5E-7
     * "BaseVolume" => 0.2093689
     * "BuyBaseVolume" => 1.96602119
     * "SellBaseVolume" => 633837063.42196
     */
    public function getMarkets(string $baseMarket = null) : array
    {
        $parameters = [];

        if ($baseMarket !== null) {
            $parameters['baseMarket'] = $baseMarket;
        }

        $result = json_decode($this->apiCall("GetMarkets", $parameters), true);

        if (!$result['Success']) {
            throw new \Exception("Can't get markets, Error: " . $result['Error']);
        }

        return $result['Data'];
    }

    public function updatePrices()
    {
        $result = json_decode($this->apiCall("GetMarkets", []), true);
        if ($result['Success'] == "true") {
            $json = $result['Data'];
        } else {
            throw new \Exception("Can't get markets, Error: " . $result['Error']);
        }
        foreach ($json as $pair) {
            $this->prices[$pair['Label']]['high'] = $pair['High'];
            $this->prices[$pair['Label']]['low'] = $pair['Low'];
            $this->prices[$pair['Label']]['bid'] = $pair['BidPrice'];
            $this->prices[$pair['Label']]['ask'] = $pair['AskPrice'];
            $this->prices[$pair['Label']]['last'] = $pair['LastPrice'];
            $this->prices[$pair['Label']]['time'] = '';  // not available on Cryptopia
        }
    }

    // @todo add setBalance

    /**
     * "CurrencyId" => 508
     * "Symbol" => "ATMS"
     * "Total" => 998.00399202
     * "Available" => 0.0
     * "Unconfirmed" => 0.0
     * "HeldForTrades" => 998.00399202
     * "PendingWithdraw" => 0.0
     * "Address" => null
     * "Status" => "OK"
     * "StatusMessage" => null
     * "BaseAddress" => null
     */
    public function getBalance() : array
    {
        $result = $this->apiCall("GetBalance", ['Currency' => ""]); // "" for All currency balances
        $result = json_decode($result, true);

        if (!$result['Success']) {
            throw new \Exception("Can't get balances, Error: " . $result['Error']);
        }

        return $result['Data'];
    }

    Public function getCurrencyBalance($currency)
    {
        $result = $this->apiCall("GetBalance", ['Currency' => $currency]);
        $result = json_decode($result, true);
        if ($result['Success'] == "true") {
            return $result['Data'][0]['Total'];
        } else {
            throw new \Exception("Can't get balance, Error: " . $result['Error']);
        }
    }

    public function cancelOrder($id) : void
    {
        $result = $this->apiCall("CancelTrade", ['Type' => "Trade", 'OrderId' => $id]);
        $result = json_decode($result, true);

        if (!$result['Success']) {
            throw new \Exception("Can't Cancel Order # $id, Error: " . $result['Error']);
        }
    }

    public function cancelAll() : void
    {
        $result = $this->apiCall("CancelTrade", ['Type' => "All"]);
        $result = json_decode($result, true);

        if (!$result['Success']) {
            throw new \Exception("Can't Cancel All Orders, Error: " . $result['Error']);
        }
    }

    /**
     * "OrderId" => 30260548
     * "TradePairId" => 5050
     * "Market" => "ATMS/BTC"
     * "Type" => "Sell"
     * "Rate" => 0.0001
     * "Amount" => 998.00399202
     * "Total" => 0.0998004
     * "Remaining" => 998.00399202
     * "TimeStamp" => "2017-05-31T19:33:53.7332086"
     */
    public function getOpenOrders($tradePairId = null) : ?array
    {
        $parameters = [];
        $parameters['TradePairId'] = (string) $tradePairId;

        $result = json_decode($this->apiCall("GetOpenOrders", $parameters), true);

        if (!$result['Success']) {
            throw new \Exception("Can't Cancel All Orders, Error: " . $result['Error']);
        }

        return $result['Data'];
    }

    public function activeOrders($symbol = "")
    {
        if ($symbol == "") {
            $apiParams = ['TradePairId' => ""];
        } else {
            $apiParams = ['TradePairId' => $this->getExchangeSymbol($symbol)];
        }
        $myOrders = json_decode($this->apiCall("GetOpenOrders", $apiParams), true);
        //print_r($myOrders);
        // There is a bug in the API if you send no parameters it will return Success:true Error: Market not found.
        // Array
        // (
        //    [Success] => 1
        //    [Message] =>
        //    [Data] =>
        //    [Error] => Market not found.
        // )

        $orders = [];
        $price = [];  // sort by price
        if ($myOrders['Success'] == "true" && $myOrders['Error'] == "") {
            foreach ($myOrders['Data'] as $order) {
                $orderSymbol = $this->makeStandardSymbol($order["Market"]); // convert to standard format currency pair
                $orders[] = ["symbol" => $orderSymbol, "type" => $order["Type"], "price" => $order["Rate"],
                    "amount" => $order["Remaining"], "id" => $order["OrderId"]];
                if ($order["Type"] == "Sell") {
                    $price[] = 0 - $order['Rate'];  // lowest ask price if first
                } else {
                    $price[] = $order['Rate'];
                }
            }
            if ($orders) // If there are any orders
                array_multisort($price, SORT_DESC, $orders); // sort orders by price
        } else {
            throw new \Exception("Can't get active orders, Error: " . $myOrders['Error']);
        }

        return $orders;
    }

    public function orderStatus($id)
    {

    }

    public function buy($symbol, $amount, $price)
    {
        return $this->placeOrder($symbol, $amount, $price, 'Buy');
    }

    public function placeOrder($tradePairId, $amount, $price, $side)
    {
        $result = $this->apiCall("SubmitTrade", ['Type' => $side, 'TradePairId' => $tradePairId,
            'Rate' => number_format((float)$price, 8, '.', ''), 'Amount' => number_format((float)$amount, 8, '.', '')]);
        $result = json_decode($result, true);
        if ($result['Success'] == "true") {
            return (int) $result['Data']['OrderId'];
        } else {
            throw new \Exception("Can't Place Order, Error: " . $result['Error']); //*** die instead of echo
        }
    }

    public function sell($symbol, $amount, $price)
    {
        return $this->placeOrder($symbol, $amount, $price, 'Sell');
    }

    public function marketOrderbook($symbol)
    {
        $mktOrders = json_decode($this->apiCall("GetMarketOrders", ['TradePairId' => $this->getExchangeSymbol($symbol)]), true);
        unset($orders);
        if ($mktOrders['Success'] == "true" && $mktOrders['Error'] == "") {
            //print_r($mktOrders);
            foreach ($mktOrders['Data'] as $orderType => $order) {
                foreach ($order as $ordersByType) {
                    // $standardSymbol = $this->getStandardSymbol($symbol);  // @todo not yet implemented
                    $orders[] = ["symbol" => $symbol, "type" => $orderType, "price" => $ordersByType["Price"],
                        "amount" => $ordersByType["Volume"]];
                }
            }
        } else {
            throw new \Exception("Can't get orderbook, Error: " . $mktOrders['Error']);
        }

        return $orders;
    }

}

?>
