<?php

use App\Mail\CryptoApiMail;
use App\Mail\CryptoException;
use Carbon\Carbon;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Function to buy coin at market price

if (!function_exists('placeBuyOrder')) {
    function placeBuyOrder($symbol, $buy_for_usdt, $current_price, $trader, $estimated_profit, $stop_loss, $dif_lim, $dea_lim, $per_lim)
    {

        if ($trader == 1) {
            $apiKey = env('BINANCE_API_KEY_1');
            $apiSecret = env('BINANCE_API_SECRET_1');
        } else if ($trader == 2) {
            $apiKey = env('BINANCE_API_KEY_2');
            $apiSecret = env('BINANCE_API_SECRET_2');
        } else if ($trader == 3) {
            $apiKey = env('BINANCE_API_KEY_3');
            $apiSecret = env('BINANCE_API_SECRET_3');
        } else if ($trader == 4) {
            $apiKey = env('BINANCE_API_KEY_4');
            $apiSecret = env('BINANCE_API_SECRET_4');
        }

        // Get server time from Binance API
        $serverTime = json_decode(file_get_contents('https://api.binance.com/api/v3/time'), true);
        $serverTimestamp = $serverTime['serverTime'];

        // Calculate timestamp and recvWindow
        $timestamp = round(microtime(true) * 1000);
        $recvWindow = 5000;

        // Adjust timestamp if necessary
        if ($timestamp - $serverTimestamp > $recvWindow) {
            $timestamp = $serverTimestamp + $recvWindow;
        }

        // Fetch exchange information to get LOT_SIZE filter
        $exchangeInfo = json_decode(file_get_contents("https://api.binance.com/api/v3/exchangeInfo?symbol=$symbol"), true);
        $filters = $exchangeInfo['symbols'][0]['filters'];

        // Extract LOT_SIZE filter values
        $lotSize = null;
        foreach ($filters as $filter) {
            if ($filter['filterType'] == 'LOT_SIZE') {
                $lotSize = $filter;
                break;
            }
        }

        if ($lotSize === null) {
            throw new Exception("LOT_SIZE filter not found for symbol $symbol");
        }

        // Calculate and adjust the quantity
        $quantity = $buy_for_usdt / $current_price;
        $quantity = floor($quantity / $lotSize['stepSize']) * $lotSize['stepSize'];

        // Ensure quantity is within the allowed limits
        if ($quantity < $lotSize['minQty'] || $quantity > $lotSize['maxQty']) {
            throw new Exception("Quantity $quantity is outside the allowed LOT_SIZE limits for symbol $symbol");
        }

        $url = 'https://api.binance.com/api/v3/order';
        $query = http_build_query([
            'symbol' => $symbol,
            'side' => 'BUY',
            'type' => 'MARKET',
            'quantity' => strval($quantity),
            'timestamp' => $timestamp,
            'signature' => hash_hmac('sha256', 'symbol=' . $symbol . '&side=BUY&type=MARKET&quantity=' . strval($quantity) . '&timestamp=' . $timestamp, $apiSecret),
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-MBX-APIKEY: ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        $response = json_decode($response, true);
        $fee_details = getTotalCommission($response);
        if (!isset($response['symbol'])) {
            Log::info('Trader ' . $trader . ': Buy response' . json_encode($response));
        }
        $data =  [
            'symbol' => $response['symbol'],
            'orderId' => $response['orderId'],
            'status' => $response['status'],
            'type' => $response['type'],
            'side' => $response['side'],
            'price' => $current_price,
            'trade_status' => 'open',
            'trade_acc' => $trader,
            'qty' => $quantity,
            'commission' => $fee_details['totalCommission'],
            'commission_asset' => $fee_details['commissionAsset'],
            'commissionUSDT' => $fee_details['commissionAssetUSDT'],
            'created_at' => Carbon::now('Asia/Karachi'),
        ];

        DB::table('orders')->insert(
            $data
        );
        $profitPercentage = $estimated_profit;
        $fee = $data['commissionUSDT'] * $data['commission'] * 2;

        $target_sell_price = round($current_price * (1 + $profitPercentage / 100), 4);

        $total_sell_price = $target_sell_price * $data['qty'];
        $total_buy_price = $data['price'] * $data['qty'];
        $total_profit = $total_sell_price - $total_buy_price;

        if ($total_profit <= $fee) {
            $target_sell_price = $data['price'] + $fee / $data['qty'] + ($target_sell_price * 0.001);
            $profitPercentage = (($target_sell_price - $data['price']) / $data['price']) * 100;
        }

        $stop_loss = round($current_price * (1 + $stop_loss / 100), 4);

        $data['dif_lim'] = $dif_lim;
        $data['dif_lim'] = $dif_lim;
        $data['dea_lim'] = $dea_lim;
        $data['per_lim'] = $per_lim;
        $data['trade_amount'] = number_format($buy_for_usdt, 2, '.', '');

        sendEmail($data);
        $data['target_profit_percentage'] = $profitPercentage;
        $data['target_sell_price'] = $target_sell_price;
        $data['stop_loss'] = $stop_loss;
        $data['fee'] = $fee;


        return $data;
    }
}


function placeSellOrder($symbol, $quantity, $current_price, $trader, $estimated_profit, $stop_loss, $dif_lim, $dea_lim, $per_lim)
{

    if ($trader == 1) {
        $apiKey = env('BINANCE_API_KEY_1');
        $apiSecret = env('BINANCE_API_SECRET_1');
    } else if ($trader == 2) {
        $apiKey = env('BINANCE_API_KEY_2');
        $apiSecret = env('BINANCE_API_SECRET_2');
    } else if ($trader == 3) {
        $apiKey = env('BINANCE_API_KEY_3');
        $apiSecret = env('BINANCE_API_SECRET_3');
    } else if ($trader == 4) {
        $apiKey = env('BINANCE_API_KEY_4');
        $apiSecret = env('BINANCE_API_SECRET_4');
    }

    // Get server time from Binance API
    $serverTime = json_decode(file_get_contents('https://api.binance.com/api/v3/time'), true);
    $serverTimestamp = $serverTime['serverTime'];

    // Calculate timestamp and recvWindow
    $timestamp = round(microtime(true) * 1000);
    $recvWindow = 5000;

    // Adjust timestamp if necessary
    if ($timestamp - $serverTimestamp > $recvWindow) {
        $timestamp = $serverTimestamp + $recvWindow;
    }

    // Fetch exchange information to get LOT_SIZE filter
    $exchangeInfo = json_decode(file_get_contents("https://api.binance.com/api/v3/exchangeInfo?symbol=$symbol"), true);
    $filters = $exchangeInfo['symbols'][0]['filters'];

    // Extract LOT_SIZE filter values
    $lotSize = null;
    foreach ($filters as $filter) {
        if ($filter['filterType'] == 'LOT_SIZE') {
            $lotSize = $filter;
            break;
        }
    }

    if ($lotSize === null) {
        throw new Exception("LOT_SIZE filter not found for symbol $symbol");
    }

    // Calculate and adjust the quantity
    $quantity = floor($quantity / $lotSize['stepSize']) * $lotSize['stepSize'];

    // Ensure quantity is within the allowed limits
    if ($quantity < $lotSize['minQty'] || $quantity > $lotSize['maxQty']) {
        throw new Exception("Quantity $quantity is outside the allowed LOT_SIZE limits for symbol $symbol");
    }

    $url = 'https://api.binance.com/api/v3/order';
    $query = http_build_query([
        'symbol' => $symbol,
        'side' => 'SELL',
        'type' => 'MARKET',
        'quantity' => strval($quantity),
        'timestamp' => $timestamp,
        'signature' => hash_hmac('sha256', 'symbol=' . $symbol . '&side=SELL&type=MARKET&quantity=' . strval($quantity) . '&timestamp=' . $timestamp, $apiSecret),
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-MBX-APIKEY: ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $response = json_decode($response, true);
    // return $response;
    $fee_details = getTotalCommission($response);

    if (!isset($response['symbol'])) {
        Log::info('Trader ' . $trader . ': Sell response' . json_encode($response));
    }
    $data =  [
        'symbol' => $response['symbol'],
        'orderId' => $response['orderId'],
        'status' => $response['status'],
        'type' => $response['type'],
        'side' => $response['side'],
        'price' => $current_price,
        'trade_status' => 'open',
        'trade_acc' => $trader,
        'qty' => $quantity,
        'commission' => $fee_details['totalCommission'],
        'commission_asset' => $fee_details['commissionAsset'],
        'commissionUSDT' => $fee_details['commissionAssetUSDT'],
        'created_at' => Carbon::now('Asia/Karachi'),
    ];

    DB::table('orders')->insert(
        $data
    );
    $profitPercentage = $estimated_profit;
    $fee = $data['commissionUSDT'] * $data['commission'] * 2;

    $target_sell_price = round($current_price * (1 + $profitPercentage / 100), 4);

    $total_sell_price = $target_sell_price * $data['qty'];
    $total_buy_price = $data['price'] * $data['qty'];
    $total_profit = $total_sell_price - $total_buy_price;

    if ($total_profit <= $fee) {
        $target_sell_price = $data['price'] + $fee / $data['qty'] + ($target_sell_price * 0.001);
        $profitPercentage = (($target_sell_price - $data['price']) / $data['price']) * 100;
    }

    $stop_loss = round($current_price * (1 + $stop_loss / 100), 4);
    $data['target_sell_price'] = $target_sell_price;
    $data['stop_loss'] = $stop_loss;
    $data['dif_lim'] = $dif_lim;
    $data['dif_lim'] = $dif_lim;
    $data['dea_lim'] = $dea_lim;
    $data['per_lim'] = $per_lim;
    $data['target_profit_percentage'] = $profitPercentage;
    $data['trade_amount'] = number_format($quantity * $current_price, 2, '.', '');
    $data['fee'] = $fee;
    $buy_order =  DB::table('orders')
        ->where('symbol', $data['symbol'])
        ->where('trade_acc', $data['trade_acc'])
        ->where('side', 'BUY')
        ->where('trade_status', 'open')
        ->orderBy('created_at', 'DESC')
        ->first();

    DB::table('orders')
        ->where('orderId', $buy_order->orderId)
        ->where('trade_acc', $data['trade_acc'])
        ->update(
            [
                'pair_id' => $data['orderId'],
                'trade_status' => 'close',

            ]
        );
    DB::table('orders')
        ->where('orderId', $data['orderId'])
        ->where('trade_acc', $data['trade_acc'])
        ->update(
            [
                'pair_id' => $buy_order->orderId,
                'trade_status' => 'close',
            ]
        );

    $data['pair_id'] = $buy_order->orderId;
    sendEmail($data);

    return $data;
}
function getTotalCommission($apiResponse)
{
    $totalCommission = 0;
    $commissionAsset = '';

    // Check if fills array exists
    if (isset($apiResponse['fills']) && is_array($apiResponse['fills'])) {
        foreach ($apiResponse['fills'] as $fill) {
            // Sum up the commission
            $totalCommission += (float) $fill['commission'];

            // Get the commission asset (assuming it's the same for all fills)
            if (empty($commissionAsset)) {
                $commissionAsset = $fill['commissionAsset'];
            }
        }
    }

    return [
        'totalCommission' => $totalCommission,
        'commissionAsset' => $commissionAsset,
        'commissionAssetUSDT' => $commissionAsset != 'USDT' ? getCurrentPrice($commissionAsset . 'USDT') : $totalCommission,
    ];
}
// Function to sell coin at market price
function placeBuyLimitOrder($symbol, $quantity, $price, $trader, $buy_for_usdt, $profitPercentage, $stop_loss, $buy_price)
{
    if ($trader == 1) {
        $apiKey = env('BINANCE_API_KEY_1');
        $apiSecret = env('BINANCE_API_SECRET_1');
    } else if ($trader == 2) {
        $apiKey = env('BINANCE_API_KEY_2');
        $apiSecret = env('BINANCE_API_SECRET_2');
    } else if ($trader == 3) {
        $apiKey = env('BINANCE_API_KEY_3');
        $apiSecret = env('BINANCE_API_SECRET_3');
    } else if ($trader == 4) {
        $apiKey = env('BINANCE_API_KEY_4');
        $apiSecret = env('BINANCE_API_SECRET_4');
    }

    // Get server time from Binance API
    $serverTime = json_decode(file_get_contents('https://api.binance.com/api/v3/time'), true);
    $serverTimestamp = $serverTime['serverTime'];

    // Calculate timestamp and recvWindow
    $timestamp = round(microtime(true) * 1000);
    $recvWindow = 5000;

    // Adjust timestamp if necessary
    if ($timestamp - $serverTimestamp > $recvWindow) {
        $timestamp = $serverTimestamp + $recvWindow;
    }

    // Fetch exchange information to get filters
    $exchangeInfo = json_decode(file_get_contents("https://api.binance.com/api/v3/exchangeInfo?symbol=$symbol"), true);
    $filters = $exchangeInfo['symbols'][0]['filters'];

    $priceFilter = null;
    $lotSizeFilter = null;

    foreach ($filters as $filter) {
        if ($filter['filterType'] == 'PRICE_FILTER') {
            $priceFilter = $filter;
        }
        if ($filter['filterType'] == 'LOT_SIZE') {
            $lotSizeFilter = $filter;
        }
    }

    if ($priceFilter === null) {
        throw new Exception("PRICE_FILTER not found for symbol $symbol");
    }

    if ($lotSizeFilter === null) {
        throw new Exception("LOT_SIZE filter not found for symbol $symbol");
    }

    // Ensure price conforms to PRICE_FILTER
    $minPrice = $priceFilter['minPrice'];
    $maxPrice = $priceFilter['maxPrice'];
    $tickSize = $priceFilter['tickSize'];

    // Round price to nearest tickSize
    $adjustedPrice = round($price / $tickSize) * $tickSize;

    if ($adjustedPrice < $price) {
        $adjustedPrice += $tickSize;
    }

    if ($adjustedPrice < $minPrice || $adjustedPrice > $maxPrice) {
        throw new Exception("Price $adjustedPrice is outside the allowed PRICE_FILTER limits for symbol $symbol");
    }

    // Ensure quantity conforms to LOT_SIZE
    $minQty = $lotSizeFilter['minQty'];
    $maxQty = $lotSizeFilter['maxQty'];
    $stepSize = $lotSizeFilter['stepSize'];

    // Round quantity to nearest stepSize
    $adjustedQuantity = floor($quantity / $stepSize) * $stepSize;

    if ($adjustedQuantity < $quantity) {
        $adjustedQuantity += $stepSize;
    }

    if ($adjustedQuantity < $minQty || $adjustedQuantity > $maxQty) {
        throw new Exception("Quantity $adjustedQuantity is outside the allowed LOT_SIZE limits for symbol $symbol");
    }

    $url = 'https://api.binance.com/api/v3/order';
    $queryData = [
        'symbol' => $symbol,
        'side' => 'BUY',
        'type' => 'LIMIT',
        'timeInForce' => 'GTC',
        'price' => strval($adjustedPrice),
        'quantity' => strval($adjustedQuantity),
        'timestamp' => $timestamp,
        'recvWindow' => $recvWindow,
    ];

    $query = http_build_query($queryData);

    // Generate the signature
    $signature = hash_hmac('sha256', $query, $apiSecret);

    // Append the signature to the query string
    $queryData['signature'] = $signature;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($queryData));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-MBX-APIKEY: ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $response = json_decode($response, true);
    Log::info('Trader ' . $trader . ': Buy Limit quantity ' . $adjustedQuantity);
    if (!isset($response['symbol'])) {
        Log::info('Trader ' . $trader . ': Buy Limit response ' . json_encode($response));
    }

    $data = [
        'symbol' => $response['symbol'],
        'orderId' => $response['orderId'],
        'status' => $response['status'],
        'type' => $response['type'],
        'side' => $response['side'],
        'price' => $adjustedPrice,
        'trade_acc' => $trader,
        'trade_status' => 'open',
        'qty' => $adjustedQuantity
    ];
    DB::table('orders')->insert($data);

    $stop_loss = round($buy_price * (1 + $stop_loss / 100), 4);
    $data['target_sell_price'] = $adjustedPrice;
    $data['target_profit_percentage'] = $profitPercentage;
    $data['stop_loss'] = $stop_loss;
    $data['trade_amount'] = number_format($buy_for_usdt, 2, '.', '');

    sendEmail($data);
    return $response;
}


// Function to sell coin at market price
function placeSellLimitOrder($symbol, $quantity, $price, $trader, $buy_for_usdt, $profitPercentage, $stop_loss, $buy_price)
{
    if ($trader == 1) {
        $apiKey = env('BINANCE_API_KEY_1');
        $apiSecret = env('BINANCE_API_SECRET_1');
    } else if ($trader == 2) {
        $apiKey = env('BINANCE_API_KEY_2');
        $apiSecret = env('BINANCE_API_SECRET_2');
    } else if ($trader == 3) {
        $apiKey = env('BINANCE_API_KEY_3');
        $apiSecret = env('BINANCE_API_SECRET_3');
    } else if ($trader == 4) {
        $apiKey = env('BINANCE_API_KEY_4');
        $apiSecret = env('BINANCE_API_SECRET_4');
    }

    // Get server time from Binance API
    $serverTime = json_decode(file_get_contents('https://api.binance.com/api/v3/time'), true);
    $serverTimestamp = $serverTime['serverTime'];

    // Calculate timestamp and recvWindow
    $timestamp = round(microtime(true) * 1000);
    $recvWindow = 5000;

    // Adjust timestamp if necessary
    if ($timestamp - $serverTimestamp > $recvWindow) {
        $timestamp = $serverTimestamp + $recvWindow;
    }

    // Fetch exchange information to get filters
    $exchangeInfo = json_decode(file_get_contents("https://api.binance.com/api/v3/exchangeInfo?symbol=$symbol"), true);
    $filters = $exchangeInfo['symbols'][0]['filters'];

    $priceFilter = null;
    $lotSizeFilter = null;

    foreach ($filters as $filter) {
        if ($filter['filterType'] == 'PRICE_FILTER') {
            $priceFilter = $filter;
        }
        if ($filter['filterType'] == 'LOT_SIZE') {
            $lotSizeFilter = $filter;
        }
    }

    if ($priceFilter === null) {
        throw new Exception("PRICE_FILTER not found for symbol $symbol");
    }

    if ($lotSizeFilter === null) {
        throw new Exception("LOT_SIZE filter not found for symbol $symbol");
    }

    // Ensure price conforms to PRICE_FILTER
    $minPrice = $priceFilter['minPrice'];
    $maxPrice = $priceFilter['maxPrice'];
    $tickSize = $priceFilter['tickSize'];

    // Round price to nearest tickSize
    $adjustedPrice = round($price / $tickSize) * $tickSize;

    if ($adjustedPrice < $price) {
        $adjustedPrice += $tickSize;
    }

    if ($adjustedPrice < $minPrice || $adjustedPrice > $maxPrice) {
        throw new Exception("Price $adjustedPrice is outside the allowed PRICE_FILTER limits for symbol $symbol");
    }

    // Ensure quantity conforms to LOT_SIZE
    $minQty = $lotSizeFilter['minQty'];
    $maxQty = $lotSizeFilter['maxQty'];
    $stepSize = $lotSizeFilter['stepSize'];

    // Round quantity to nearest stepSize
    $adjustedQuantity = floor($quantity / $stepSize) * $stepSize;

    if ($adjustedQuantity < $quantity) {
        $adjustedQuantity += $stepSize;
    }

    if ($adjustedQuantity < $minQty || $adjustedQuantity > $maxQty) {
        throw new Exception("Quantity $adjustedQuantity is outside the allowed LOT_SIZE limits for symbol $symbol");
    }

    $url = 'https://api.binance.com/api/v3/order';
    $queryData = [
        'symbol' => $symbol,
        'side' => 'SELL',
        'type' => 'LIMIT',
        'timeInForce' => 'GTC',
        'price' => strval($adjustedPrice),
        'quantity' => strval($adjustedQuantity),
        'timestamp' => $timestamp,
        'recvWindow' => $recvWindow,
    ];

    $query = http_build_query($queryData);

    // Generate the signature
    $signature = hash_hmac('sha256', $query, $apiSecret);

    // Append the signature to the query string
    $queryData['signature'] = $signature;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($queryData));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-MBX-APIKEY: ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $response = json_decode($response, true);
    Log::info('Trader ' . $trader . ': Buy Limit quantity ' . $adjustedQuantity);
    if (!isset($response['symbol'])) {
        Log::info('Trader ' . $trader . ': Buy Limit response ' . json_encode($response));
    }

    $data = [
        'symbol' => $response['symbol'],
        'orderId' => $response['orderId'],
        'status' => $response['status'],
        'type' => $response['type'],
        'side' => $response['side'],
        'price' => $adjustedPrice,
        'trade_acc' => $trader,
        'trade_status' => 'open',
        'qty' => $adjustedQuantity
    ];
    DB::table('orders')->insert($data);

    $stop_loss = round($buy_price * (1 + $stop_loss / 100), 4);
    $data['target_sell_price'] = $adjustedPrice;
    $data['target_profit_percentage'] = $profitPercentage;
    $data['stop_loss'] = $stop_loss;
    $data['trade_amount'] = number_format($buy_for_usdt, 2, '.', '');

    sendEmail($data);
    return $response;
}



// Function to sell coin at market price
function placeSellMarketOrder($symbol, $quantity, $price, $trader)
{
    if ($trader == 1) {
        $apiKey = env('BINANCE_API_KEY_1');
        $apiSecret = env('BINANCE_API_SECRET_1');
    } else if ($trader == 2) {
        $apiKey = env('BINANCE_API_KEY_2');
        $apiSecret = env('BINANCE_API_SECRET_2');
    } else if ($trader == 3) {
        $apiKey = env('BINANCE_API_KEY_3');
        $apiSecret = env('BINANCE_API_SECRET_3');
    } else if ($trader == 4) {
        $apiKey = env('BINANCE_API_KEY_4');
        $apiSecret = env('BINANCE_API_SECRET_4');
    }

    // Get server time from Binance API
    $serverTime = json_decode(file_get_contents('https://api.binance.com/api/v3/time'), true);
    $serverTimestamp = $serverTime['serverTime'];

    // Calculate timestamp and recvWindow
    $timestamp = round(microtime(true) * 1000);
    $recvWindow = 5000; // Example recvWindow value in milliseconds

    // Check if the timestamp falls within the recvWindow
    if ($timestamp - $serverTimestamp > $recvWindow) {
        // Adjust the timestamp if it falls outside the recvWindow
        $timestamp = $serverTimestamp + $recvWindow;
    }
    $url = 'https://api.binance.com/api/v3/order';
    $query = http_build_query([
        'symbol' => $symbol,
        'side' => 'SELL',
        'type' => 'MARKET',
        'quantity' => strval($quantity),
        'timestamp' => $timestamp,
        'signature' => hash_hmac('sha256', 'symbol=' . $symbol . '&side=SELL&type=MARKET&quantity=' . strval($quantity) . '&timestamp=' . $timestamp, $apiSecret),
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-MBX-APIKEY: ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $response = json_decode($response, true);
    $fee_details = getTotalCommission($response);
    if (!isset($response['symbol'])) {
        Log::info('Trader ' . $trader . ': Sell Market response' . json_encode($response));
    }
    $data = [
        'symbol' => $response['symbol'],
        'orderId' => $response['orderId'],
        'status' => $response['status'],
        'type' => $response['type'],
        'side' => $response['side'],
        'price' => $price,
        'trade_acc' => $trader,
        'trade_status' => 'close',
        'qty' => $quantity,
        'commission' => $fee_details['totalCommission'],
        'commission_asset' => $fee_details['commissionAsset'],
        'commissionUSDT' => $fee_details['commissionAssetUSDT'],

    ];
    DB::table('orders')->insert(
        $data
    );

    sendEmail($data);
    return $response;
}

function cancelOrder($symbol, $orderId, $trader)
{
    // Determine API keys based on trader
    if ($trader == 1) {
        $apiKey = env('BINANCE_API_KEY_1');
        $apiSecret = env('BINANCE_API_SECRET_1');
    } else if ($trader == 2) {
        $apiKey = env('BINANCE_API_KEY_2');
        $apiSecret = env('BINANCE_API_SECRET_2');
    } else if ($trader == 3) {
        $apiKey = env('BINANCE_API_KEY_3');
        $apiSecret = env('BINANCE_API_SECRET_3');
    } else if ($trader == 4) {
        $apiKey = env('BINANCE_API_KEY_4');
        $apiSecret = env('BINANCE_API_SECRET_4');
    }

    // Get server time from Binance API
    $serverTime = json_decode(file_get_contents('https://api.binance.com/api/v3/time'), true);
    $serverTimestamp = $serverTime['serverTime'];

    // Calculate timestamp and recvWindow
    $timestamp = round(microtime(true) * 1000);
    $recvWindow = 5000; // Example recvWindow value in milliseconds

    // Check if the timestamp falls within the recvWindow
    if ($timestamp - $serverTimestamp > $recvWindow) {
        // Adjust the timestamp if it falls outside the recvWindow
        $timestamp = $serverTimestamp + $recvWindow;
    }

    // Create the query string for the API request
    $query = http_build_query([
        'symbol' => $symbol,
        'orderId' => $orderId,
        'timestamp' => $timestamp,
        'recvWindow' => $recvWindow,
    ]);

    // Generate the signature
    $signature = hash_hmac('sha256', $query, $apiSecret);

    // Append the signature to the query string
    $query .= '&signature=' . $signature;

    // Set the URL for the API request
    $url = 'https://api.binance.com/api/v3/order?' . $query;

    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-MBX-APIKEY: ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Execute the cURL request
    $response = curl_exec($ch);
    curl_close($ch);

    // Decode the response
    $response = json_decode($response, true);

    // Log the response if an error occurs
    if (!isset($response['symbol'])) {
        Log::info('Trader ' . $trader . ': Cancel response' . json_encode($response));
    }

    // Return the response
    return $response;
}



function getCurrentPrice($symbol)
{
    $url = 'https://api.binance.com/api/v3/ticker/price?symbol=' . $symbol;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    $ticker = json_decode($response, true);
    return isset($ticker['price']) ? $ticker['price'] : '0';
}


function getAllOrders($symbol, $trader, $orderId)
{
    if ($trader == 1) {
        $apiKey = env('BINANCE_API_KEY_1');
        $apiSecret = env('BINANCE_API_SECRET_1');
    } else if ($trader == 2) {
        $apiKey = env('BINANCE_API_KEY_2');
        $apiSecret = env('BINANCE_API_SECRET_2');
    } else if ($trader == 3) {
        $apiKey = env('BINANCE_API_KEY_3');
        $apiSecret = env('BINANCE_API_SECRET_3');
    } else if ($trader == 4) {
        $apiKey = env('BINANCE_API_KEY_4');
        $apiSecret = env('BINANCE_API_SECRET_4');
    }
    // Get server time from Binance API
    $serverTimeResponse = Http::get('https://api.binance.com/api/v3/time');

    if (!$serverTimeResponse->successful()) {
        error_log('Failed to retrieve server time: ' . $serverTimeResponse->body());
        return null;
    }

    $serverTime = $serverTimeResponse->json();
    $serverTimestamp = $serverTime['serverTime'];

    // Calculate timestamp and recvWindow
    $timestamp = round(microtime(true) * 1000);
    $recvWindow = 5000; // Example recvWindow value in milliseconds

    // Check if the timestamp falls within the recvWindow
    if ($timestamp - $serverTimestamp > $recvWindow) {
        // Adjust the timestamp if it falls outside the recvWindow
        $timestamp = $serverTimestamp + $recvWindow;
    }

    // Request parameters
    $params = [
        'symbol' => $symbol,
        'orderId' => $orderId,
        'timestamp' => $timestamp,
        'recvWindow' => $recvWindow,
    ];

    // Generate query string
    $queryString = http_build_query($params);

    // Generate signature
    $signature = hash_hmac('sha256', $queryString, $apiSecret);

    // Append signature to the query string
    $params['signature'] = $signature;

    // Make the HTTP request
    $response = Http::withHeaders([
        'X-MBX-APIKEY' => $apiKey,
    ])->get('https://api.binance.com/api/v3/order', $params);

    // Check if the response is successful
    if ($response->successful()) {
        return $response->json();
    } else {
        // Log the full response for debugging
        error_log('API request failed: ' . $response->body());
        return null;
    }
}
function getCandleData($symbol, $interval)
{

    $limit = 200; // Number of candlesticks
    $response = Http::withOptions(['verify' => false])->get('https://api.binance.com/api/v3/klines', [
        'symbol' => $symbol,
        'interval' => $interval,
        'limit' => $limit,
    ]);

    // Extracting the relevant data from the API response
    $candlestickData = $response->json();
    $closePrices = [];
    $highPrices = [];
    $lowPrices = [];
    $ma7Values = []; // Moving Average values for period 7
    $ma25Values = []; // Moving Average values for period 25
    $sarValues = []; // SAR values

    // Extract close, high, and low prices
    foreach ($candlestickData as $data) {
        $closePrices[] = $data[4]; // Close price
        $highPrices[] = $data[2]; // High price
        $lowPrices[] = $data[3]; // Low price
    }

    // Calculate Moving Averages (MA)
    $periods = [7, 25];
    foreach ($periods as $period) {
        for ($i = $period - 1; $i < count($closePrices); $i++) {
            ${"ma{$period}Values"}[] = array_sum(array_slice($closePrices, $i - $period + 1, $period)) / $period;
        }
    }

    // Calculate SAR
    $af = 0.02;
    $max_af = 0.2;
    $uptrend = true; // Initial trend assumption
    $ep = $highPrices[0]; // Extreme Point
    $sar = $lowPrices[0]; // Starting SAR value

    for ($i = 1; $i < count($closePrices); $i++) {
        if ($uptrend) {
            $sar = $sar + $af * ($ep - $sar);

            if ($highPrices[$i] > $ep) {
                $ep = $highPrices[$i];
                $af = min($af + 0.02, $max_af);
            }

            if ($lowPrices[$i] < $sar) {
                $uptrend = false;
                $sar = $ep;
                $ep = $lowPrices[$i];
                $af = 0.02;
            }
        } else {
            $sar = $sar - $af * ($sar - $ep);

            if ($lowPrices[$i] < $ep) {
                $ep = $lowPrices[$i];
                $af = min($af + 0.02, $max_af);
            }

            if ($highPrices[$i] > $sar) {
                $uptrend = true;
                $sar = $ep;
                $ep = $highPrices[$i];
                $af = 0.02;
            }
        }

        $sarValues[] = $sar;
    }

    // Extract current and previous 5 values of MA(7), MA(25), and SAR
    $currentMA7 = end($ma7Values);
    $previousMA7 = array_slice($ma7Values, -6, 5);
    $currentMA25 = end($ma25Values);
    $previousMA25 = array_slice($ma25Values, -6, 5);
    $currentSAR = end($sarValues);
    $previousSAR = array_slice($sarValues, -6, 5);


    return [
        'currentMA7' => $currentMA7,
        'previousMA7' => $previousMA7,
        'currentMA25' => $currentMA25,
        'previousMA25' => $previousMA25,
        'currentSAR' => floatval($currentSAR),
        'previousSAR' => $previousSAR,
        'closePrices' => $closePrices, // Send close prices for x-axis labels
    ];
}
function getHistoricalData($symbol, $interval, $limit)
{
    $response = Http::withOptions(['verify' => false])->get('https://api.binance.com/api/v3/klines', [
        'symbol' => $symbol,
        'interval' => $interval,
        'limit' => $limit,
    ]);

    return $response->json();
}

function calculateEMA($prices, $period)
{
    $k = 2 / ($period + 1);
    $emaArray = [];
    $emaArray[] = array_sum(array_slice($prices, 0, $period)) / $period;

    for ($i = $period; $i < count($prices); $i++) {
        $ema = $prices[$i] * $k + end($emaArray) * (1 - $k);
        $emaArray[] = $ema;
    }

    return $emaArray;
}

function calculateMACD($closePrices)
{
    $shortPeriod = 12;
    $longPeriod = 26;
    $signalPeriod = 9;

    $shortEMA = calculateEMA($closePrices, $shortPeriod);
    $longEMA = calculateEMA($closePrices, $longPeriod);

    $dif = [];
    for ($i = 0; $i < count($shortEMA); $i++) {
        $dif[] = $shortEMA[$i] - $longEMA[$i];
    }

    $dea = calculateEMA($dif, $signalPeriod);

    $macdHistogram = [];
    for ($i = 0; $i < count($dea); $i++) {
        $macdHistogram[] = 2 * ($dif[$i] - $dea[$i]);
    }

    return [
        'DIF' => $dif,
        'DEA' => $dea,
        'MACD_Histogram' => $macdHistogram,
    ];
}
function calculateEMACandle($prices, $period)
{
    $k = 2 / ($period + 1);
    $ema = [];
    $ema[0] = $prices[0]; // Start EMA with the first price

    for ($i = 1; $i < count($prices); $i++) {
        $ema[$i] = $prices[$i] * $k + $ema[$i - 1] * (1 - $k);
    }

    return $ema;
}
function getCandleStickData($symbol, $interval)
{
    $limit = 200; // Number of candlesticks

    $response = Http::withOptions(['verify' => false])->get('https://api.binance.com/api/v3/klines', [
        'symbol' => $symbol,
        'interval' => $interval,
        'limit' => $limit,
    ]);
    $candlestickData = $response->json();
    $closePrices = [];
    $highPrices = [];
    $lowPrices = [];
    $percentageChanges = []; // To store percentage changes
    $rsiValues = []; // To store RSI values
    $ma7Values = []; // Moving Average values for period 7
    $ma25Values = []; // Moving Average values for period 25
    $ma99Values = []; // Moving Average values for period 99
    $sarValues = []; // SAR values

    // Extract close, high, and low prices
    foreach ($candlestickData as $data) {
        $closePrices[] = $data[4]; // Close price
        $highPrices[] = $data[2]; // High price
        $lowPrices[] = $data[3]; // Low price
    }

    // Calculate Moving Averages (MA)
    $periods = [7, 25, 99];
    foreach ($periods as $period) {
        for ($i = $period - 1; $i < count($closePrices); $i++) {
            ${"ma{$period}Values"}[] = array_sum(array_slice($closePrices, $i - $period + 1, $period)) / $period;
        }
    }

    // Calculate SAR
    $af = 0.02;
    $max_af = 0.2;
    $uptrend = true; // Initial trend assumption
    $ep = $highPrices[0]; // Extreme Point
    $sar = $lowPrices[0]; // Starting SAR value

    for ($i = 1; $i < count($closePrices); $i++) {
        if ($uptrend) {
            $sar = $sar + $af * ($ep - $sar);

            if ($highPrices[$i] > $ep) {
                $ep = $highPrices[$i];
                $af = min($af + 0.02, $max_af);
            }

            if ($lowPrices[$i] < $sar) {
                $uptrend = false;
                $sar = $ep;
                $ep = $lowPrices[$i];
                $af = 0.02;
            }
        } else {
            $sar = $sar - $af * ($sar - $ep);

            if ($lowPrices[$i] < $ep) {
                $ep = $lowPrices[$i];
                $af = min($af + 0.02, $max_af);
            }

            if ($highPrices[$i] > $sar) {
                $uptrend = true;
                $sar = $ep;
                $ep = $highPrices[$i];
                $af = 0.02;
            }
        }

        $sarValues[] = $sar;
    }

    // Calculate MACD, DIF, and DEA
    $shortPeriod = 12;
    $longPeriod = 26;
    $signalPeriod = 9;

    $shortEMA = [];
    $longEMA = [];
    $dif = [];
    $dea = [];
    $macdHistogram = [];

    // Calculate short-term and long-term EMAs
    $shortEMA = calculateEMACandle($closePrices, $shortPeriod);
    $longEMA = calculateEMACandle($closePrices, $longPeriod);

    // Calculate DIF line
    for ($i = 0; $i < count($closePrices); $i++) {
        $dif[$i] = $shortEMA[$i] - $longEMA[$i];
    }

    // Calculate DEA line (EMA of DIF)
    $dea = calculateEMACandle($dif, $signalPeriod);

    // Calculate MACD Histogram
    for ($i = 0; $i < count($dif); $i++) {
        $macdHistogram[$i] = 2 * ($dif[$i] - $dea[$i]);
    }

    // RSI calculation (14 periods by default)
    $rsiPeriod = 17;
    $gains = [];
    $losses = [];

    for ($i = 1; $i < count($closePrices); $i++) {
        $change = $closePrices[$i] - $closePrices[$i - 1];
        $gain = $change > 0 ? $change : 0;
        $loss = $change < 0 ? abs($change) : 0;

        $gains[] = $gain;
        $losses[] = $loss;

        if ($i >= $rsiPeriod) {
            $avgGain = array_sum(array_slice($gains, $i - $rsiPeriod + 1, $rsiPeriod)) / $rsiPeriod;
            $avgLoss = array_sum(array_slice($losses, $i - $rsiPeriod + 1, $rsiPeriod)) / $rsiPeriod;

            $rs = $avgLoss == 0 ? 0 : $avgGain / $avgLoss;
            $rsi = $avgLoss == 0 ? 100 : 100 - (100 / (1 + $rs));

            $rsiValues[] = $rsi;
        } else {
            $rsiValues[] = 50; // Middle value before RSI period is met
        }
    }

    // Calculate percentage change
    for ($i = 1; $i < count($closePrices); $i++) {
        $percentageChange = (($closePrices[$i] - $closePrices[$i - 1]) / $closePrices[$i - 1]) * 100;
        $percentageChanges[] = $percentageChange;
    }

    // Extract current and previous 5 values of MA(7), MA(25), MA(99), SAR, DIF, DEA, MACD Histogram, and RSI
    $currentMA7 = end($ma7Values);
    $previousMA7 = array_slice($ma7Values, -6, 5);
    $currentMA25 = end($ma25Values);
    $previousMA25 = array_slice($ma25Values, -6, 5);
    $currentMA99 = end($ma99Values);
    $previousMA99 = array_slice($ma99Values, -6, 5);
    $currentSAR = end($sarValues);
    $previousSAR = array_slice($sarValues, -6, 5);

    $currentDIF = end($dif);
    $previousDIF = array_slice($dif, -6, 5);
    $currentDEA = end($dea);
    $previousDEA = array_slice($dea, -6, 5);
    $currentMACD = end($macdHistogram);
    $previousMACD = array_slice($macdHistogram, -6, 5);

    $currentRSI = end($rsiValues);
    $previousRSI = array_slice($rsiValues, -6, 5);

    // Calculate percentage change for the current closing price
    $currentClose = end($closePrices);
    $previousClose = $closePrices[count($closePrices) - 2]; // Second last closing price
    $currentPercentageChange = (($currentClose - $previousClose) / $previousClose) * 100;

    // Compile the data to return
    $data = [
        'currentMA7' => $currentMA7,
        'previousMA7' => $previousMA7,
        'currentMA25' => $currentMA25,
        'previousMA25' => $previousMA25,
        'currentMA99' => $currentMA99,
        'previousMA99' => $previousMA99,
        'currentSAR' => $currentSAR,
        'previousSAR' => $previousSAR,
        'closePrices' => $closePrices, // Send close prices for x-axis labels
        'currentDIF' => $currentDIF,
        'previousDIF' => $previousDIF,
        'currentDEA' => $currentDEA,
        'previousDEA' => $previousDEA,
        'currentMACD' => $currentMACD,
        'previousMACD' => $previousMACD,
        'percentageChanges' => $percentageChanges,
        'currentPercentageChange' => $currentPercentageChange, // Add current percentage change
        'currentRSI' => $currentRSI,
        'previousRSI' => $previousRSI,
        'rsiValues' => $rsiValues, // Add all RSI values
    ];

    return $data;
}


function getCandleStickDataDump($symbol, $interval)
{
    $limit = 100; // Number of candlesticks

    $response = Http::withOptions(['verify' => false])->get('https://api.binance.com/api/v3/klines', [
        'symbol' => $symbol,
        'interval' => $interval,
        'limit' => $limit,
    ]);
    $candlestickData = $response->json();
    $closePrices = [];
    $highPrices = [];
    $lowPrices = [];
    $volumes = [];
    $ma7Values = []; // Moving Average values for period 7
    $ma25Values = []; // Moving Average values for period 25
    $ma99Values = []; // Moving Average values for period 99
    $sarValues = []; // SAR values

    // Extract close, high, low prices, and volumes
    foreach ($candlestickData as $data) {
        $closePrices[] = $data[4]; // Close price
        $highPrices[] = $data[2]; // High price
        $lowPrices[] = $data[3]; // Low price
        $volumes[] = $data[5]; // Volume
    }

    // Calculate Moving Averages (MA)
    $periods = [7, 25, 99];
    foreach ($periods as $period) {
        for ($i = $period - 1; $i < count($closePrices); $i++) {
            ${"ma{$period}Values"}[] = array_sum(array_slice($closePrices, $i - $period + 1, $period)) / $period;
        }
    }

    // Calculate SAR
    $af = 0.02;
    $max_af = 0.2;
    $uptrend = true; // Initial trend assumption
    $ep = $highPrices[0]; // Extreme Point
    $sar = $lowPrices[0]; // Starting SAR value

    for ($i = 1; $i < count($closePrices); $i++) {
        if ($uptrend) {
            $sar = $sar + $af * ($ep - $sar);

            if ($highPrices[$i] > $ep) {
                $ep = $highPrices[$i];
                $af = min($af + 0.02, $max_af);
            }

            if ($lowPrices[$i] < $sar) {
                $uptrend = false;
                $sar = $ep;
                $ep = $lowPrices[$i];
                $af = 0.02;
            }
        } else {
            $sar = $sar - $af * ($sar - $ep);

            if ($lowPrices[$i] < $ep) {
                $ep = $lowPrices[$i];
                $af = min($af + 0.02, $max_af);
            }

            if ($highPrices[$i] > $sar) {
                $uptrend = true;
                $sar = $ep;
                $ep = $highPrices[$i];
                $af = 0.02;
            }
        }

        $sarValues[] = $sar;
    }

    // Calculate MACD, DIF, and DEA (Signal Line)
    $shortPeriod = 12;
    $longPeriod = 26;
    $signalPeriod = 9;

    $shortEMA = calculateEMACandle($closePrices, $shortPeriod);
    $longEMA = calculateEMACandle($closePrices, $longPeriod);

    $dif = [];
    $dea = [];
    $macdHistogram = [];

    // Calculate DIF line
    for ($i = 0; $i < count($closePrices); $i++) {
        $dif[$i] = $shortEMA[$i] - $longEMA[$i];
    }

    // Calculate DEA line (Signal Line, which is the EMA of DIF)
    $dea = calculateEMACandle($dif, $signalPeriod);

    // Calculate MACD Histogram
    for ($i = 0; $i < count($dif); $i++) {
        $macdHistogram[$i] = 2 * ($dif[$i] - $dea[$i]);
    }

    // Calculate percentage change
    $percentageChanges = [];
    for ($i = 1; $i < count($closePrices); $i++) {
        $percentageChange = (($closePrices[$i] - $closePrices[$i - 1]) / $closePrices[$i - 1]) * 100;
        $percentageChanges[] = $percentageChange;
    }

    // RSI calculation (14 periods by default)
    $rsiPeriod = 14;
    $gains = [];
    $losses = [];
    $rsiValues = [];

    for ($i = 1; $i < count($closePrices); $i++) {
        $change = $closePrices[$i] - $closePrices[$i - 1];
        $gain = $change > 0 ? $change : 0;
        $loss = $change < 0 ? abs($change) : 0;

        $gains[] = $gain;
        $losses[] = $loss;

        if ($i >= $rsiPeriod) {
            $avgGain = array_sum(array_slice($gains, $i - $rsiPeriod + 1, $rsiPeriod)) / $rsiPeriod;
            $avgLoss = array_sum(array_slice($losses, $i - $rsiPeriod + 1, $rsiPeriod)) / $rsiPeriod;

            $rs = $avgLoss == 0 ? 0 : $avgGain / $avgLoss;
            $rsi = $avgLoss == 0 ? 100 : 100 - (100 / (1 + $rs));

            $rsiValues[] = $rsi;
        } else {
            $rsiValues[] = 50; // Middle value before RSI period is met
        }
    }
    $currentRSI = end($rsiValues);
    $previousRSI = array_slice($rsiValues, -6, 5);

    // Calculate percentage change for the current closing price
    $currentClose = end($closePrices);
    $previousClose = $closePrices[count($closePrices) - 2]; // Second last closing price
    $currentPercentageChange = (($currentClose - $previousClose) / $previousClose) * 100;
    $currentPrice = getCurrentPrice($symbol);
    $current_candle = Carbon::now('Asia/Karachi');

    // Handle candle timing based on interval
    $candle_interval = explode('m', $interval)[0];
    $candle_interval = intval($candle_interval);
    $minutes = $current_candle->minute;

    // Check if the minutes are not a multiple of 3
    if ($minutes % $candle_interval !== 0) {
        // Calculate the lower multiple of 3
        $new_minutes = floor($minutes / $candle_interval) * $candle_interval;
        // Set the new minutes while keeping other parts of the timestamp the same
        $current_candle->setTime($current_candle->hour, $new_minutes, 0);
    } else {
        $current_candle->setTime($current_candle->hour, $current_candle->minute, 0);
    }


    // Prepare data dump with the calculated values
    $data_dump = [
        'symbol' => $symbol,
        'interval' => $interval,
        'currentPrice' => $currentPrice,
        'currentMA7' => end($ma7Values),
        'currentMA25' => end($ma25Values),
        'currentMA99' => end($ma99Values),
        'currentSAR' => end($sarValues),
        'closePrices' => json_encode($closePrices),
        'currentDIF' => end($dif),
        'currentDEA' => end($dea),
        'currentMACD' => end($macdHistogram),
        'currentSignalLine' => end($dea), // This is the Signal Line (EMA of DIF)
        'currentPercentageChange' => $currentPercentageChange,
        'created_at' => Carbon::now('Asia/Karachi'),
        'current_candle' => $current_candle,
        'currentVolume' => end($volumes), // Adding current volume here, but not saving to DB
    ];

    // Check if the record exists and update or insert accordingly
    $existing_record = DB::table('candlestick_data')->where('created_at', $data_dump['created_at'])->first();
    if (!empty($existing_record)) {
        DB::table('candlestick_data')->where('id', $existing_record->id)->update($data_dump);
    } else {
        DB::table('candlestick_data')->insert($data_dump);
    }

    // Delete old records if more than 5000
    $totalRecords = DB::table('candlestick_data')->where('symbol', $symbol)->count();
    if ($totalRecords > 5000) {
        $recordsToDelete = $totalRecords - 5000;
        DB::table('candlestick_data')
            ->orderBy('created_at', 'asc')
            ->limit($recordsToDelete)
            ->delete();
    }

    // Add RSI to data dump
    $data_dump['currentRSI'] = $currentRSI;
    $data_dump['previousRSI'] = $previousRSI;

    return $data_dump;
}


function runCommand($command)
{
    // Execute the command
    $output = [];
    $returnVar = null;
    exec($command, $output, $returnVar);

    // Return the output as a response
    return response()->json([
        'output' => $output,
        'returnVar' => $returnVar
    ]);
}

function sendEmail($details)
{
    $recipient1 = "anasj5749@gmail.com";
    $recipient2 = "drupalmind@gmail.com";


    Mail::to($recipient1)->send(new CryptoApiMail($details));
    Mail::to($recipient2)->send(new CryptoApiMail($details));
}
function sendEmailException($details)
{
    $recipient1 = "anasj5749@gmail.com";
    $recipient2 = "drupalmind@gmail.com";


    Mail::to($recipient1)->send(new CryptoException($details));
    Mail::to($recipient2)->send(new CryptoException($details));
}








// Function to sell coin at market price
function placeLimitOrder($symbol, $quantity, $price, $side, $trader, $buy_for_usdt, $profitPercentage, $stop_loss, $buy_price)
{
    if ($trader == 1) {
        $apiKey = env('BINANCE_API_KEY_1');
        $apiSecret = env('BINANCE_API_SECRET_1');
    } else if ($trader == 2) {
        $apiKey = env('BINANCE_API_KEY_2');
        $apiSecret = env('BINANCE_API_SECRET_2');
    } else if ($trader == 3) {
        $apiKey = env('BINANCE_API_KEY_3');
        $apiSecret = env('BINANCE_API_SECRET_3');
    } else if ($trader == 4) {
        $apiKey = env('BINANCE_API_KEY_4');
        $apiSecret = env('BINANCE_API_SECRET_4');
    }

    // Get server time from Binance API
    $serverTime = json_decode(file_get_contents('https://api.binance.com/api/v3/time'), true);
    $serverTimestamp = $serverTime['serverTime'];

    // Calculate timestamp and recvWindow
    $timestamp = round(microtime(true) * 1000);
    $recvWindow = 5000;

    // Adjust timestamp if necessary
    if ($timestamp - $serverTimestamp > $recvWindow) {
        $timestamp = $serverTimestamp + $recvWindow;
    }

    // Fetch exchange information to get filters
    $exchangeInfo = json_decode(file_get_contents("https://api.binance.com/api/v3/exchangeInfo?symbol=$symbol"), true);
    $filters = $exchangeInfo['symbols'][0]['filters'];

    $priceFilter = null;
    $lotSizeFilter = null;

    foreach ($filters as $filter) {
        if ($filter['filterType'] == 'PRICE_FILTER') {
            $priceFilter = $filter;
        }
        if ($filter['filterType'] == 'LOT_SIZE') {
            $lotSizeFilter = $filter;
        }
    }

    if ($priceFilter === null) {
        throw new Exception("PRICE_FILTER not found for symbol $symbol");
    }

    if ($lotSizeFilter === null) {
        throw new Exception("LOT_SIZE filter not found for symbol $symbol");
    }

    // Ensure price conforms to PRICE_FILTER
    $minPrice = $priceFilter['minPrice'];
    $maxPrice = $priceFilter['maxPrice'];
    $tickSize = $priceFilter['tickSize'];

    // Round price to nearest tickSize
    $adjustedPrice = round($price / $tickSize) * $tickSize;

    if ($adjustedPrice < $price) {
        $adjustedPrice += $tickSize;
    }

    if ($adjustedPrice < $minPrice || $adjustedPrice > $maxPrice) {
        throw new Exception("Price $adjustedPrice is outside the allowed PRICE_FILTER limits for symbol $symbol");
    }

    // Ensure quantity conforms to LOT_SIZE
    $minQty = $lotSizeFilter['minQty'];
    $maxQty = $lotSizeFilter['maxQty'];
    $stepSize = $lotSizeFilter['stepSize'];

    // Round quantity to nearest stepSize
    $adjustedQuantity = floor($quantity / $stepSize) * $stepSize;

    if ($adjustedQuantity < $quantity) {
        $adjustedQuantity += $stepSize;
    }

    if ($adjustedQuantity < $minQty || $adjustedQuantity > $maxQty) {
        throw new Exception("Quantity $adjustedQuantity is outside the allowed LOT_SIZE limits for symbol $symbol");
    }

    $url = 'https://api.binance.com/api/v3/order';
    $queryData = [
        'symbol' => $symbol,
        'side' => $side,
        'type' => 'LIMIT',
        'timeInForce' => 'GTC',
        'price' => strval($adjustedPrice),
        'quantity' => strval($adjustedQuantity),
        'timestamp' => $timestamp,
        'recvWindow' => $recvWindow,
    ];

    $query = http_build_query($queryData);

    // Generate the signature
    $signature = hash_hmac('sha256', $query, $apiSecret);

    // Append the signature to the query string
    $queryData['signature'] = $signature;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($queryData));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-MBX-APIKEY: ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $response = json_decode($response, true);
    $fee_details = getTotalCommission($response);
    Log::info('Trader ' . $trader . ': Order Request: ' . json_encode($queryData));
    Log::info('Trader ' . $trader . ': Order Response: ' . json_encode($response));

    $data = [
        'symbol' => $response['symbol'],
        'orderId' => $response['orderId'],
        'status' => $response['status'],
        'type' => $response['type'],
        'side' => $response['side'],
        'price' => $adjustedPrice,
        'trade_acc' => $trader,
        'trade_status' => 'open',
        'qty' => $adjustedQuantity,
        'commission' => $fee_details['totalCommission'],
        'commission_asset' => $fee_details['commissionAsset'],
        'commissionUSDT' => $fee_details['commissionAssetUSDT'],
    ];
    DB::table('orders')->insert($data);

    $stop_loss = round($buy_price * (1 + $stop_loss / 100), 4);
    $data['target_sell_price'] = $adjustedPrice;
    $data['target_profit_percentage'] = $profitPercentage;
    $data['stop_loss'] = $stop_loss;
    $data['trade_amount'] = number_format($buy_for_usdt, 2, '.', '');

    sendEmail($data);
    Log::info('Trader ' . $trader . ': Order Dumped in Database');
    return $data;
}
function curlPost($url, $data)
{

    // Convert the data array to JSON
    $json_data = json_encode($data);

    // Initialize cURL session
    $ch = curl_init($url);

    // Set cURL options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json_data)
    ]);

    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    // Execute the cURL request
    $response = curl_exec($ch);
    return json_decode($response, true);
}
function getTopGainers($limit = 10)
{
    // Binance API endpoint
    $url = "https://api.binance.com/api/v3/ticker/24hr";

    // Initialize cURL session
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Execute cURL session and get the response
    $response = curl_exec($ch);
    curl_close($ch);

    // Convert response to array
    $tickers = json_decode($response, true);

    // Check if the response is valid
    if (!$tickers || isset($tickers['code'])) {
        echo "Error fetching data";
        return;
    }

    // Filter USDT pairs
    $usdtPairs = array_filter($tickers, function ($ticker) {
        return substr($ticker['symbol'], -4) === "USDT"; // Check if the symbol ends with "USDT"
    });

    // Sort USDT pairs by percentage change
    usort($usdtPairs, function ($a, $b) {
        return $b['priceChangePercent'] - $a['priceChangePercent'];
    });

    // Get top gainers
    $topGainers = array_slice($usdtPairs, 0, $limit);

    return $topGainers;
}
function getTopVolumeCoins($limit = 10)
{
    // Binance API endpoint
    $url = "https://api.binance.com/api/v3/ticker/24hr";

    // Initialize cURL session
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Execute cURL session and get the response
    $response = curl_exec($ch);
    curl_close($ch);

    // Convert response to array
    $tickers = json_decode($response, true);

    // Check if the response is valid
    if (!$tickers || isset($tickers['code'])) {
        echo "Error fetching data";
        return [];
    }

    // Filter only the pairs that end with "USDT" (Crypto/USDT pairs only)
    $usdtPairs = array_filter($tickers, function ($ticker) {
        return substr($ticker['symbol'], -4) === "USDT" && strpos($ticker['symbol'], 'USDT') === (strlen($ticker['symbol']) - 4);
    });

    // Sort USDT pairs by trading volume (quoteVolume)
    usort($usdtPairs, function ($a, $b) {
        return $b['quoteVolume'] - $a['quoteVolume'];
    });

    // Get the top volume coins
    $topVolumeCoins = array_slice($usdtPairs, 0, $limit);

    // Format the response for easier understanding
    $formattedResults = [];
    foreach ($topVolumeCoins as $coin) {
        $formattedResults[] = [
            'symbol' => $coin['symbol'],
            'price' => $coin['lastPrice'],
            '24h_change' => $coin['priceChangePercent']
        ];
    }

    return $formattedResults;
}



function getCandleStickDataNew($symbol = 'BTCUSDT', $interval = '15m', $limit = 100, $timestamp = '')
{
    // Fetching candlestick data from Binance


    if ($timestamp)
        $response = Http::withOptions(['verify' => false])->get('https://api.binance.com/api/v3/klines', [
            'symbol' => $symbol,
            'interval' => $interval,
            'limit' => $limit,
            'startTime' => $timestamp,
        ]);
    else
        $response = Http::withOptions(['verify' => false])->get('https://api.binance.com/api/v3/klines', [
            'symbol' => $symbol,
            'interval' => $interval,
            'limit' => $limit,
        ]);

    // Check if the request was successful
    if (!$response->ok()) {
        throw new \Exception('Error fetching data from Binance');
    }

    // Decode response to array
    $data = $response->json();

    // Initialize arrays for calculation
    $candlesticks = [];
    $closePrices = [];

    foreach ($data as $index => $candle) {
        // Parse candlestick data
        $timestamp = $candle[0];
        $open = (float) $candle[1];
        $high = (float) $candle[2];
        $low = (float) $candle[3];
        $close = (float) $candle[4];
        $volume = (float) $candle[5];

        // Store close prices for MA and RSI calculation
        $closePrices[] = $close;

        // Calculate moving averages
        $ma7 = $index >= 6 ? array_sum(array_slice($closePrices, -7)) / 7 : null;
        $ma14 = $index >= 13 ? array_sum(array_slice($closePrices, -14)) / 14 : null;
        $ma99 = $index >= 98 ? array_sum(array_slice($closePrices, -99)) / 99 : null;

        // Calculate RSI6
        $rsi6 = null;
        if ($index >= 5) {
            $gains = $losses = 0;
            for ($i = max(1, $index - 5); $i <= $index; $i++) {
                $change = $closePrices[$i] - $closePrices[$i - 1];
                if ($change > 0) {
                    $gains += $change;
                } else {
                    $losses += abs($change);
                }
            }
            $avgGain = $gains / 6;
            $avgLoss = $losses / 6;
            $rs = $avgLoss == 0 ? 100 : $avgGain / $avgLoss;
            $rsi6 = 100 - (100 / (1 + $rs));
        }

        // Calculate percentage change
        $prevClose = $index > 0 ? $closePrices[$index - 1] : null;
        $percentageChange = $prevClose ? (($close - $prevClose) / $prevClose) * 100 : null;

        // Store candlestick data
        $candlesticks[] = [
            'timestamp' => $timestamp,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => $volume,
            'ma7' => $ma7,
            'ma14' => $ma14,
            'ma99' => $ma99,
            'rsi6' => $rsi6,
            'percentage_change' => $percentageChange,
        ];
    }

    return $candlesticks;
}


function detectOrderBlockBuyZones($symbol = 'BTCUSDT', $interval = '15m', $limit = 100)
{
    // Fetching candlestick data from Binance
    $response = Http::withOptions(['verify' => false])->get('https://api.binance.com/api/v3/klines', [
        'symbol' => $symbol,
        'interval' => $interval,
        'limit' => $limit,
    ]);

    // Check if the request was successful
    if (!$response->ok()) {
        throw new \Exception('Error fetching data from Binance');
    }

    // Decode response to array
    $data = $response->json();

    // Initialize array to store buying opportunities (timestamps)
    $buyingOpportunities = [];
    $orderBlocks = [];

    foreach ($data as $index => $candle) {
        // Parse candlestick data
        $timestamp = $candle[0];
        $open = (float) $candle[1];
        $high = (float) $candle[2];
        $low = (float) $candle[3];
        $close = (float) $candle[4];

        // Check for a bearish candle followed by a strong bullish move
        if ($index > 0) {
            $previousCandle = $data[$index - 1];
            $prevClose = (float) $previousCandle[4];

            // Detecting bullish order block: a bearish candle (previous close > open) followed by a large bullish move
            $isBearish = $prevClose > (float) $previousCandle[1];
            $strongBullishMove = $close > ($high + (($high - $low) * 0.5)); // Move more than 50% higher than low

            if ($isBearish && $strongBullishMove) {
                // Store the order block level (using the low of the last bearish candle)
                $orderBlocks[] = [
                    'candle' => $candle,
                    'index' => $index,
                ];
            }
        }

        // Check if the current price is revisiting a bullish order block zone
        foreach ($orderBlocks as $orderBlock) {
            // Buy zone: If price revisits the order block low (within a small margin)
            if ($low <= $orderBlock['order_block_price'] && $close > $low) {
                $buyingOpportunities[] = [
                    'buy_timestamp' => $timestamp,
                    'order_block_price' => $orderBlock['order_block_price'],
                    'current_price' => $close,
                ];
            }
        }
    }

    return $buyingOpportunities;
}


function getCandleStickDataDownload($symbol = 'BTCUSDT', $interval = '15m', $limit = 100)
{
    // Fetching candlestick data from Binance
    $response = Http::withOptions(['verify' => false])->get('https://api.binance.com/api/v3/klines', [
        'symbol' => $symbol,
        'interval' => $interval,
        'limit' => $limit,
    ]);

    // Check if the request was successful
    if (!$response->ok()) {
        throw new \Exception('Error fetching data from Binance');
    }

    // Decode response to array
    $data = $response->json();

    // Initialize arrays for calculation
    $candlesticks = [];
    $closePrices = [];

    foreach ($data as $index => $candle) {
        // Parse candlestick data
        $timestamp = $candle[0];
        $open = (float) $candle[1];
        $high = (float) $candle[2];
        $low = (float) $candle[3];
        $close = (float) $candle[4];
        $volume = (float) $candle[5];

        // Store close prices for MA and RSI calculation
        $closePrices[] = $close;

        // Calculate moving averages
        $ma7 = $index >= 6 ? array_sum(array_slice($closePrices, -7)) / 7 : null;
        $ma14 = $index >= 13 ? array_sum(array_slice($closePrices, -14)) / 14 : null;
        $ma99 = $index >= 98 ? array_sum(array_slice($closePrices, -99)) / 99 : null;

        // Calculate RSI6
        $rsi6 = null;
        if ($index >= 5) {
            $gains = $losses = 0;
            for ($i = max(1, $index - 5); $i <= $index; $i++) {
                $change = $closePrices[$i] - $closePrices[$i - 1];
                if ($change > 0) {
                    $gains += $change;
                } else {
                    $losses += abs($change);
                }
            }
            $avgGain = $gains / 6;
            $avgLoss = $losses / 6;
            $rs = $avgLoss == 0 ? 100 : $avgGain / $avgLoss;
            $rsi6 = 100 - (100 / (1 + $rs));
        }

        // Calculate percentage change
        $prevClose = $index > 0 ? $closePrices[$index - 1] : null;
        $percentageChange = $prevClose ? (($close - $prevClose) / $prevClose) * 100 : null;

        // Store candlestick data
        $candlesticks[] = [
            'timestamp' => $timestamp,
            'readable_timestamp' => date('H:i:s', $timestamp / 1000),
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => $volume,
            'ma7' => $ma7,
            'ma14' => $ma14,
            'ma99' => $ma99,
            'rsi6' => $rsi6,
            'percentage_change' => $percentageChange,
        ];
    }

    // Create CSV content in memory
    $headers = [
        'Content-type' => 'text/csv',
        'Content-Disposition' => 'attachment; filename="candlestick_data_' . Carbon::now('Asia/Karachi') . '.csv"',
        'Pragma' => 'no-cache',
        'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
        'Expires' => '0',
    ];

    $callback = function () use ($candlesticks) {
        $file = fopen('php://output', 'w');

        // Add CSV header
        fputcsv($file, [
            'timestamp (unix)',
            'timestamp (readable)',
            'open',
            'high',
            'low',
            'close',
            'volume',
            'ma7',
            'ma14',
            'ma99',
            'rsi6',
            'percentage_change'
        ]);

        // Add data rows
        foreach ($candlesticks as $candle) {
            fputcsv($file, [
                $candle['timestamp'],
                $candle['readable_timestamp'],
                $candle['open'],
                $candle['high'],
                $candle['low'],
                $candle['close'],
                $candle['volume'],
                $candle['ma7'],
                $candle['ma14'],
                $candle['ma99'],
                $candle['rsi6'],
                $candle['percentage_change'],
            ]);
        }

        fclose($file);
    };

    return response()->stream($callback, 200, $headers);
}
function detectBuyPoint($candlestickData)
{
    $buySignals = [];
    $previousVolume = null;

    foreach ($candlestickData as $index => $candle) {
        // Skip if there's not enough data for calculation
        if ($index < 10) {
            $previousVolume = $candle['volume'];
            continue;
        }

        // Extract relevant data
        $rsi = $candle['rsi6'];
        $volume = $candle['volume'];
        $percentageChange = $candle['percentage_change'];
        $ma7 = $candle['ma7'];
        $ma14 = $candle['ma14'];
        $ma99 = $candle['ma99'];

        // Calculate volume change (current vs previous) and average volume over last 7 candles
        $avgVolume7 = array_sum(array_column(array_slice($candlestickData, $index - 6, 7), 'volume')) / 7;
        $isVolumeSurging = $volume > $avgVolume7 * 1.5; // Volume surge (50% above avg)
        $isVolumeSurging = true;

        // Check if short MA crosses above long MA (bullish crossover)
        // $isBullishMA = $ma7 !== null && $ma14 !== null && $ma7 > $ma14 && $ma7 > $ma99;
        $isBullishMA = true;

        // Refine RSI check (RSI < 30 and rising)
        $isRSILow = $rsi !== null && $rsi < 30;
        // $isRSIRising = $index > 0 && $rsi > $candlestickData[$index - 1]['rsi6'];
        $isRSIRising = true;

        // Calculate net percentage change over last 5 candles (trend analysis)
        $netPercentageChange5 = 0;
        for ($n = $index; $n >= $index - 5; $n--) {
            $netPercentageChange5 += $candlestickData[$n]['percentage_change'];
        }

        // Detect bullish reversal pattern (e.g., price starts rising after a sharp decline)
        $isBullishReversal = $netPercentageChange5 <= -0.3;

        // Buy signal conditions
        if ($isBullishMA && $isRSILow && $isRSIRising && $isVolumeSurging && $isBullishReversal) {
            $timestamp = $candle['timestamp'] / 1000; // Convert from milliseconds to seconds

            // Create a DateTime object with the Unix timestamp
            $dateTime = new DateTime("@$timestamp");
            $dateTime->setTimezone(new DateTimeZone('Asia/Karachi')); // Set the timezone to PST (Pakistan Standard Time)

            // Format the timestamp to a readable format in PST
            $readableTimestamp = $dateTime->format('Y-m-d H:i:s');
            $buySignals[] = [
                'timestamp' => $readableTimestamp,
                'unixTimestamp' => $timestamp,
                'open' => $candle['open'],
                'high' => $candle['high'],
                'low' => $candle['low'],
                'close' => $candle['close'],
                'volume' => $candle['volume'],
                'rsi6' => $candle['rsi6'],
                'percentage_change' => $candle['percentage_change'],
                'percentage_change_5' => $netPercentageChange5,
                'index' => $index
            ];
        }

        // Update the previous volume for the next iteration
        $previousVolume = $volume;
    }

    return $buySignals;
}




// Custom function to dump large data
function candlestickDataDumpInterval($symbol = 'BTCUSDT', $interval = '1h', $limit = 1000, $days = 5)
{
    $candlesticks = [];
    $now = strtotime('now') * 1000; // Current timestamp in milliseconds
    $fiveDaysAgo = strtotime("-$days days") * 1000; // 5 days ago in milliseconds

    // Start with the current time and go backward
    $startTime = $fiveDaysAgo;

    // Loop until we cover the full 5 days
    while ($startTime < $now) {
        // Fetching candlestick data from Binance
        $response = Http::withOptions(['verify' => false])->get('https://api.binance.com/api/v3/klines', [
            'symbol' => $symbol,
            'interval' => $interval,
            'limit' => $limit,
            'startTime' => $startTime, // Set the starting time for each request
        ]);

        // Check if the request was successful
        if (!$response->ok()) {
            throw new \Exception('Error fetching data from Binance');
        }

        // Decode response to array
        $data = $response->json();

        // If no data is returned, break the loop
        if (empty($data)) {
            break;
        }

        foreach ($data as $index => $candle) {
            // Parse candlestick data
            $timestamp = $candle[0]; // Binance timestamp in milliseconds

            // Convert timestamp to readable format in Asia/Karachi timezone (dd-mm-yyyy H:i:s)
            $dateTime = new DateTime();
            $dateTime->setTimestamp($timestamp / 1000); // Convert from milliseconds to seconds
            $dateTime->setTimezone(new DateTimeZone('Asia/Karachi')); // Set timezone to Asia/Karachi
            $readableTimestamp = $dateTime->format('Y-m-d H:i:s'); // MySQL DATETIME format (YYYY-MM-DD H:i:s)

            $open = (float) $candle[1];
            $high = (float) $candle[2];
            $low = (float) $candle[3];
            $close = (float) $candle[4];

            // Insert or update the database entry, allowing duplicates for different intervals or symbols
            DB::table('candlesticks')->updateOrInsert(
                [
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'timestamp' => $readableTimestamp, // Ensures only this combination of symbol, interval, and timestamp is unique
                ],
                [
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'close' => $close,
                ]
            );

            // Add to the candlesticks array for return
            $candlesticks[] = [
                'symbol' => $symbol,
                'interval' => $interval,
                'timestamp' => $readableTimestamp,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
            ];

            // Update the startTime to the last timestamp fetched for the next batch
            $startTime = $timestamp + 1;
        }

        // Log progress
        Log::info('DataDumper: ' . 'Current Date ' . $readableTimestamp . ' Completed!');

        // Delay to prevent hitting API limits
        usleep(300000); // 300ms delay (300,000 microseconds)
    }
}

// Temporary filling functions
function candlestickDataDumpAllIntervals($symbol = 'BTCUSDT', $intervals = ['3m', '5m', '15m', '30m', '1h', '4h', '1d', '1w'], $totalCandles = 5000)
{
    $candlesticks = [];
    $now = strtotime('now') * 1000; // Current timestamp in milliseconds
    $limit = 1000; // Binance's max limit per request

    foreach ($intervals as $interval) {
        $collectedCandles = 0;
        $startTime = $now - ($totalCandles * intervalToMilliseconds($interval));

        while ($collectedCandles < $totalCandles) {
            $remainingCandles = min($limit, $totalCandles - $collectedCandles);

            // Fetching candlestick data from Binance
            $response = Http::withOptions(['verify' => false])->get('https://api.binance.com/api/v3/klines', [
                'symbol' => $symbol,
                'interval' => $interval,
                'limit' => $remainingCandles,
                'startTime' => $startTime,
            ]);

            // Check if the request was successful
            if (!$response->ok()) {
                throw new \Exception("Error fetching data from Binance for interval $interval");
            }

            $data = $response->json();
            if (empty($data)) break; // Exit if no data is returned

            foreach ($data as $candle) {
                $timestamp = $candle[0];
                $dateTime = new DateTime();
                $dateTime->setTimestamp($timestamp / 1000);
                $dateTime->setTimezone(new DateTimeZone('Asia/Karachi'));
                $readableTimestamp = $dateTime->format('Y-m-d H:i:s');

                $open = (float) $candle[1];
                $high = (float) $candle[2];
                $low = (float) $candle[3];
                $close = (float) $candle[4];

                // Insert or update in the database
                DB::table('candlesticks')->updateOrInsert(
                    [
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'timestamp' => $readableTimestamp,
                    ],
                    [
                        'open' => $open,
                        'high' => $high,
                        'low' => $low,
                        'close' => $close,
                    ]
                );

                $candlesticks[] = [
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'timestamp' => $readableTimestamp,
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'close' => $close,
                ];

                $startTime = $timestamp + 1; // Move to the next starting point
                $collectedCandles++;
            }

            Log::info("DataDumper for $interval: Fetched $collectedCandles candles so far.");

            usleep(300000); // 300ms delay
        }

        Log::info("DataDumper for $interval: Completed dumping $collectedCandles candles.");
    }

    return $candlesticks;
}

// Helper function to convert interval to milliseconds
function intervalToMilliseconds($interval)
{
    switch ($interval) {
        case '3m': return 3 * 60 * 1000;
        case '5m': return 5 * 60 * 1000;
        case '15m': return 15 * 60 * 1000;
        case '30m': return 30 * 60 * 1000;
        case '1h': return 60 * 60 * 1000;
        case '4h': return 4 * 60 * 60 * 1000;
        case '1d': return 24 * 60 * 60 * 1000;
        case '1w': return 7 * 24 * 60 * 60 * 1000;
        default: return 60 * 60 * 1000; // Default to 1 hour if interval is unknown
    }
}

// ----------------------------------------------
function requestPythonModel($endpoint, $queryArgsArr)
{
    $baseURL = 'http://170.64.198.221:5000/' . $endpoint;

    // Build the query string from the array of query parameters
    $queryString = http_build_query($queryArgsArr);

    // Construct the full URL with the query string
    $url = $baseURL . '?' . $queryString;
    

    // Make the GET request to the Python Flask API
    $response = Http::withOptions([
        'verify' => false, // This is typically used in development environments where SSL verification might be an issue
    ])->get($url);

    // Return the response body directly, could also handle the response differently depending on needs
    return $response->body(); // Adjust based on whether you expect JSON or another format
}




function startPythonServer()
{
    // Define the command and log file path
    $logFile = '/var/www/vhosts/cryptoapis.store/analytics.cryptoapis.store/python_server.log';
    $command = 'bash -c "cd /var/www/vhosts/cryptoapis.store/analytics.cryptoapis.store/ && ' .
        'source venv/bin/activate && ' .
        'nohup python3 main.py > ' . $logFile . ' 2>&1 &"';

    // Define descriptors
    $descriptors = array(
        0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
        1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
        2 => array("pipe", "w")   // stderr is a pipe, let's redirect it to stdout
    );

    // Open the process
    $proc = proc_open($command, $descriptors, $pipes);

    if (is_resource($proc)) {
        // Close child's input immediately
        fclose($pipes[0]);  // No need to write anything to stdin

        // Normally we would read from stdout here if needed, but since you're using nohup, it's already being redirected
        fclose($pipes[1]);  // Close stdout pipe, not needed here
        fclose($pipes[2]);  // Close stderr pipe, also not needed as it's redirected

        // It's important to check the process whether it's running or not
        $status = proc_get_status($proc);
        if ($status['running'] === true) {
            return response()->json(["message" => "Python Server started asynchronously."]);
        } else {
            return response()->json(["message" => "Failed to start python server."]);
        }

        // Important to free up the system resources
        proc_close($proc);
    } else {
        return response()->json(["message" => "Failed to initiate process."]);
    }
}

function stopPythonServer()
{
    // Define the command to find and kill the Python process running main.py
    $killCommand = "pkill -f 'python3 main.py'";

    // Execute the command
    exec($killCommand);

    return response()->json(["message" => "Python server stopped."]);
}
