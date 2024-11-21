<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CandleStickWorker2 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 360000000;
    public $symbol;
    public $interval;
    public $trade_acc;
    public $buy_coin_price;
    public $dynamic_stop_loss = 0;
    public $dynamic_stop_loss_percentage = 0.25; // Initial stop-loss at 1%
    public $previous_price = 0;
    public $symbol_counter = 1;
    /**
     * Create a new job instance.
     *
     * @param string $symbol
     * @param string $interval
     * @param float $buy_coin_price
     * @param string $trade_acc
     */
    public function __construct($symbol, $interval, $buy_coin_price, $trade_acc)
    {
        $this->symbol = DB::table('top_gainers_queue')->find($this->symbol_counter)->symbol;
        $this->interval = $interval;
        $this->trade_acc = $trade_acc;
        $this->buy_coin_price = $buy_coin_price;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::table('candlestick_data')->truncate();
        // Log::info('CandleStickWorker2 Previous records flushed ');
        Log::info('CandleStickWorker2 started for Symbol: ' . $this->symbol);
        Log::info('CandleStickWorker2: Candle Interval: ' . $this->interval);
        Log::info('CandleStickWorker2: BUY Coin Price: ' . $this->buy_coin_price);
        Log::info('CandleStickWorker2: Trade Account: ' . $this->trade_acc);


        while (true) {
            try {
                $this->symbol = DB::table('top_gainers_queue')->find($this->symbol_counter)->symbol;

                // Check for open orders
                $api_response = $this->checkOpenOrder();

                if ($api_response['is_open']) {
                    Log::info('CandleStickWorker2: Open order found.');
                    $this->manageOpenOrder($api_response['order']);
                    continue;
                } else {
                    Log::info('CandleStickWorker2: No open orders found.');
                }

                $candleData = getCandleStickDataNew($this->symbol, $this->interval, 100);
                $buyPoints = detectBuyPoint($candleData);
                
                $timestamp = $buyPoints[count($buyPoints) - 1]['unixTimestamp'];

                // Get the current time in Unix timestamp (in seconds)
                $currentTimestamp = time();

                // Calculate the difference in seconds
                $timeDifference = ($currentTimestamp - $timestamp) / 60;

                // Check if the difference is under 5 minutes (300 seconds)
                $candleInterval = intval(explode('m', $this->interval)[0]);
                $isUnderInterval = $timeDifference <= $candleInterval;

                // Evaluate buy conditions
                if ($isUnderInterval) {
                    Log::info('CandleStickWorker2: Buy conditions met, executing buy order.');
                    $this->executeBuy();
                } else {
                    Log::info('CandleStickWorker2: Buy conditions not met.');
                }
            } catch (\Exception $e) {
                Log::error('CandleStickWorker2: Error - ' . $e->getMessage());
                Log::error($e->getTraceAsString());
            }

            // Delay to prevent API rate limits
            if ($this->symbol_counter == 15) {
                $this->symbol_counter = 1;
            } else {
                $this->symbol_counter++;
            }
            usleep(300000); // 300 ms
        }
    }



    /**
     * Check if there is an open order for the current symbol and trade account.
     *
     * @return array
     */
    private function checkOpenOrder(): array
    {
        $api_data = [
            'botApiKey' => "fc621b21-00be-4c9e-899d-dccca11462b6",
            'symbol'    => $this->symbol,
            'trade_acc' => $this->trade_acc
        ];

        $url = 'https://cryptoapis.store/check-open-order';
        $api_response = curlPost($url, $api_data);

        Log::info('CandleStickWorker2: checkOpenOrder API response: ', $api_response);

        return $api_response;
    }

    /**
     * Manage an existing open order by updating stop-loss or executing a sell if conditions are met.
     *
     * @param array $buy_order
     * @param array $data
     * @return void
     */
    private function manageOpenOrder(array $buy_order): void
    {
        $current_price = getCurrentPrice($this->symbol);
        $current_profit = (($current_price - $buy_order['price']) / $buy_order['price']) * 100;

        Log::info('CandleStickWorker2: Current Price: ' . $current_price);
        Log::info('CandleStickWorker2: Buy Order Price: ' . $buy_order['price']);
        Log::info('CandleStickWorker2: Current Profit: ' . $current_profit . '%');
        $isSellingAllowed = true;
        // Update trailing stop-loss if profit threshold is met and price is increasing
        if ($current_profit > 0.3 && $current_price > $this->previous_price) {
            $this->dynamic_stop_loss_percentage = min(0.7, $this->dynamic_stop_loss_percentage * 0.9); // Tighter stop-loss as profit increases
            $new_stop_loss = $current_price * (100 - $this->dynamic_stop_loss_percentage) / 100;

            Log::info('CandleStickWorker2: Updated Stop-Loss Percentage: ' . $this->dynamic_stop_loss_percentage . '%');
            Log::info('CandleStickWorker2: New Stop Loss: ' . $new_stop_loss);

            // Ensure stop-loss does not go below the buy price
            $this->dynamic_stop_loss = max($this->dynamic_stop_loss, $new_stop_loss, $buy_order['price'] * 1.0026);
            Log::info('CandleStickWorker2: Updated Stop Loss: ' . $this->dynamic_stop_loss);
        }

        $this->previous_price = $current_price;

        Log::info('CandleStickWorker2: Dynamic Stop Loss Updated to: ' . $this->dynamic_stop_loss);


        $lastOrderTimestamp = Carbon::parse($buy_order['created_at']);  // Ensure it's a Carbon instance and remove milliseconds
        $currentTime = Carbon::now('Asia/Karachi')->toDateTimeString();

        // if($buy_order['price'] > $current_price){
        //     $isSellingAllowed = $lastOrderTimestamp->diffInMinutes($currentTime)  > 30 ;
        // }else{
        //     $isSellingAllowed = true;
        // }


        // Sell if current price drops below the dynamic stop-loss
        if ($current_price < $this->dynamic_stop_loss && $isSellingAllowed) {
            Log::info('CandleStickWorker2: Current price below stop-loss, executing sell.');
            $this->executeSell($buy_order['qty'], $current_price);
        }
    }

    /**
     * Determine if the conditions to execute a buy order are met.
     *
     * @param array $data
     * @param array $previous_candle
     * @return bool
     */

    /**
     * Execute a buy order.
     *
     * @param array $data
     * @return void
     */
    private function executeBuy(): void
    {
        $current_price = floatval(getCurrentPrice($this->symbol));
        $target_buy_price = $current_price;
        $quantity = $this->buy_coin_price / $target_buy_price;

        Log::info('CandleStickWorker2: Executing Buy Order for ' . $this->symbol);
        Log::info('CandleStickWorker2: Current Price: ' . $current_price);
        Log::info('CandleStickWorker2: Target Buy Price: ' . $target_buy_price);
        Log::info('CandleStickWorker2: Quantity to Buy: ' . $quantity);



        // Prepare API data for buying
        $api_data = [
            'botApiKey'     => "fc621b21-00be-4c9e-899d-dccca11462b6",
            'symbol'        => $this->symbol,
            'buy_for_usdt'  => $this->buy_coin_price,
            'current_price' => $current_price,
            'trade_acc'     => $this->trade_acc,
            'estimated_profit' => 0,
            'stop_loss'     => -5, // Placeholder, will be updated
            'dif_lim'       => 0,
            'dea_lim'       => 0,
            'per_lim'       => 0,
        ];

        $url = 'https://cryptoapis.store/buy-market';
        $response = curlPost($url, $api_data);

        if (isset($response['price'])) {
            // Set initial dynamic stop-loss
            $this->dynamic_stop_loss = $response['price'] * (100 - $this->dynamic_stop_loss_percentage * 0.9) / 100;
            $this->previous_price = $response['price'];

            Log::info('CandleStickWorker2: Buy Order Executed. Response: ' . json_encode($response));
            Log::info('CandleStickWorker2: Initial Stop Loss set at: ' . $this->dynamic_stop_loss);
        } else {
            Log::error('CandleStickWorker2: Buy Order Failed. Response: ' . json_encode($response));
        }
    }

    /**
     * Execute a sell order.
     *
     * @param float $quantity
     * @param float $current_price
     * @return void
     */
    private function executeSell(float $quantity, float $current_price): void
    {
        Log::info('CandleStickWorker2: Executing Sell Order for ' . $this->symbol);
        Log::info('CandleStickWorker2: Quantity to Sell: ' . $quantity);
        Log::info('CandleStickWorker2: Current Price: ' . $current_price);

        // Prepare API data for selling
        $api_data = [
            'botApiKey'         => "fc621b21-00be-4c9e-899d-dccca11462b6",
            'symbol'            => $this->symbol,
            'quantity'          => $quantity,
            'current_price'     => $current_price,
            'trade_acc'         => $this->trade_acc,
            'estimated_profit'  => 0,
            'stop_loss'         => $this->dynamic_stop_loss,
            'dif_lim'           => 0,
            'dea_lim'           => 0,
            'per_lim'           => 0,
        ];

        $url = 'https://cryptoapis.store/sell-market';
        $response = curlPost($url, $api_data);

        if (isset($response['success']) && $response['success']) {
            Log::info('CandleStickWorker2: Sell Order Executed Successfully.');
            // Reset stop-loss
            $this->dynamic_stop_loss = 0;
            $this->previous_price = 0;
        } else {
            Log::error('CandleStickWorker2: Sell Order Failed. Response: ' . json_encode($response));
        }
    }
}
