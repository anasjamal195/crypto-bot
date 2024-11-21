<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CandleStickWorker3 implements ShouldQueue
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
    public $offered_profit = 0.3;

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
        // Log::info('CandleStickWorker3 Previous records flushed ');
        Log::info('CandleStickWorker3: Candle Interval: ' . $this->interval);
        Log::info('CandleStickWorker3: BUY Coin Price: ' . $this->buy_coin_price);
        Log::info('CandleStickWorker3: Trade Account: ' . $this->trade_acc);


        while (true) {
            try {
                $this->symbol = DB::table('top_gainers_queue')->find($this->symbol_counter)->symbol;
                Log::info('CandleStickWorker3 Current Symbol: ' . $this->symbol);

                $candles = getCandleStickDataNew($this->symbol, $this->interval, '10');
                $index = count($candles) - 1;

                // Check for open orders
                $api_response = $this->checkOpenOrder();

                if ($api_response['is_open']) {
                    Log::info('CandleStickWorker3: Open order found.');
                    $this->manageOpenOrder($api_response['order']);
                    if ($this->symbol_counter == 4) {
                        $this->symbol_counter = 1;
                    } else {
                        $this->symbol_counter++;
                    }
                    continue;
                } else {
                    Log::info('CandleStickWorker2: No open orders found.');
                }

                $difference_1 = round($candles[$index - 1]['close'] - $candles[$index - 1]['open'], 2);
                $difference_2 = round($candles[$index - 2]['close'] - $candles[$index - 2]['open'], 2);
                $difference_3 = round($candles[$index - 3]['close'] - $candles[$index - 3]['open'], 2);
                $difference_5 = round($candles[$index - 5]['close'] - $candles[$index - 5]['open'], 2);
                Log::info('CandleStickWorker3: Differences: ( ' . $difference_1 . ' ) ' . ' ( ' . $difference_2 . ' ) ' . ' ( ' . $difference_3 . ' ) ' . ' ( ' . $difference_5 . ' ) ');
                if ($difference_1 > 0 && $difference_2 > 0 && $difference_3 > 0  && $difference_5 > 0) {
                    Log::info('CandleStickWorker3: Buy conditions met, executing buy order.');
                    $this->executeBuy();
                }
            } catch (\Exception $e) {
                Log::error('CandleStickWorker3: Error - ' . $e->getMessage());
                Log::error($e->getTraceAsString());
            }

            // Delay to prevent API rate limits
            if ($this->symbol_counter == 4) {
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

        Log::info('CandleStickWorker3: checkOpenOrder API response: ', $api_response);

        // Check if the API response is empty
        if (empty($api_response)) {
            return ['is_open' => false];
        }

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

        Log::info('CandleStickWorker3: Current Price: ' . $current_price);
        Log::info('CandleStickWorker3: Buy Order Price: ' . $buy_order['price']);
        Log::info('CandleStickWorker3: Current Profit: ' . $current_profit . '%');

        $this->previous_price = $current_price;

        if ($current_profit >=  $this->offered_profit) {
            Log::info('CandleStickWorker3: Current profit Conditions met, executing sell.');
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

        Log::info('CandleStickWorker3: Executing Buy Order for ' . $this->symbol);
        Log::info('CandleStickWorker3: Current Price: ' . $current_price);
        Log::info('CandleStickWorker3: Target Buy Price: ' . $target_buy_price);
        Log::info('CandleStickWorker3: Quantity to Buy: ' . $quantity);



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

            Log::info('CandleStickWorker3: Buy Order Executed. Response: ' . json_encode($response));
            Log::info('CandleStickWorker3: Initial Stop Loss set at: ' . $this->dynamic_stop_loss);
        } else {
            Log::error('CandleStickWorker3: Buy Order Failed. Response: ' . json_encode($response));
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
        Log::info('CandleStickWorker3: Executing Sell Order for ' . $this->symbol);
        Log::info('CandleStickWorker3: Quantity to Sell: ' . $quantity);
        Log::info('CandleStickWorker3: Current Price: ' . $current_price);

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
            Log::info('CandleStickWorker3: Sell Order Executed Successfully.');
            // Reset stop-loss
            $this->dynamic_stop_loss = 0;
            $this->previous_price = 0;
        } else {
            Log::error('CandleStickWorker3: Sell Order Failed. Response: ' . json_encode($response));
        }
    }
}
