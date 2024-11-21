<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class Trader1 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $timeout = 360000;
    public $symbol;
    public $interval;
    public $estimated_profit;
    public $buy_coin_price;
    public $stop_limit;
    public $dif_lim;
    public $dea_lim;
    public $per_lim;
    public $timeout_limit;
    public $trade_acc;


    public function __construct($symbol, $interval, $stop_limit, $timeout_limit, $buy_coin_price, $trade_acc)
    {
        $this->symbol = $symbol;
        $this->interval = $interval;
        $this->stop_limit = $stop_limit;
        $this->buy_coin_price = $buy_coin_price;
        $this->trade_acc = $trade_acc;
        $this->timeout_limit = $timeout_limit;
    }


    public function handle(): void
    {



        Log::info('Trader 1: Auto Trade Started.');
        Log::info('Trader 1: Symbol: ' . $this->symbol);
        Log::info('Trader 1: Candle Interval: ' . $this->interval);
        Log::info('Trader 1: Profit: ' . $this->estimated_profit . '%');
        Log::info('Trader 1: Stop Loss Limit: ' . $this->stop_limit);
        Log::info('Trader 1: Timeout Limit: ' . $this->timeout_limit);
        Log::info('Trader 1: Amount: ' . $this->buy_coin_price);
        Log::info('Trader 1: Trade Account: ' . $this->trade_acc);
        while (true) {
            try {

                // Get current Open order details
                $current_open_order_sell = DB::table('orders')->where('symbol', $this->symbol)->where('side', 'SELL')->where('type', 'LIMIT')->where('trade_acc', $this->trade_acc)->where('trade_status', 'open')->orderBy('created_at', 'desc')->first();
                // $current_open_order_buy = DB::table('orders')->where('symbol', $this->symbol)->where('side', 'BUY')->where('type', 'MARKET')->where('trade_acc', $this->trade_acc)->where('trade_status', 'open')->orderBy('created_at', 'desc')->first();

                $current_open_limit_order_buy = DB::table('orders')->where('symbol', $this->symbol)->where('side', 'BUY')->where('type', 'LIMIT')->where('trade_acc', $this->trade_acc)->where('trade_status', 'open')->orderBy('created_at', 'desc')->first();

                if (!empty($current_open_order_sell) && !empty($current_open_limit_order_buy)) {
                    // Check for previous open order
                    Log::info('Trader 1: Another order is open ' . $current_open_order_sell->orderId);
                    $current_buy_price = $current_open_limit_order_buy->price;
                    $current_quantity = $current_open_limit_order_buy->qty;

                    $current_order_api = getAllOrders($this->symbol, $this->trade_acc, $current_open_order_sell->orderId);
                    if ($current_order_api['status'] == 'FILLED' || $current_order_api['status'] == 'CANCELED') {

                        DB::table('orders')
                            ->where('id', $current_open_limit_order_buy->id)
                            ->update(['trade_status' => 'close']);
                        DB::table('orders')
                            ->where('id', $current_open_order_sell->id)
                            ->update(['trade_status' => 'close', 'status' => 'CANCELED']);
                        Log::info('Trader 1: Previous Trade Closed ');
                    } else {
                        $current_price = floatval(getCurrentPrice($this->symbol));
                        $stopLimitPercentage = $this->stop_limit;
                        $stop_limit_amount = round($current_buy_price * (1 + $stopLimitPercentage / 100), 4);

                        // =============Timeout order cancel conditions================================================


                        // $stop_time_limit = (($current_buy_price - $current_price) / $current_price) * 100;

                        // =============================================================================================
                        //     if (($current_price  <= $stop_limit_amount)

                        //     	|| (Carbon::now()->greaterThan($timeoutTimestamp) &&  $stop_time_limit > 0.4)
                        // )


                        $createdAt = Carbon::parse($current_open_order_sell->created_at);
                        $timeoutLimit = $this->timeout_limit; // duration in minutes
                        $timeoutTimestamp = $createdAt->copy()->addMinutes($timeoutLimit);

                        if ($current_price  <= $stop_limit_amount || Carbon::now()->greaterThan($timeoutTimestamp)) {

                            //Cancel this order
                            Log::info('Trader 1: Cancelling order ');
                            $cancel_response = cancelOrder($this->symbol, $current_open_order_sell->orderId, $this->trade_acc);
                            Log::info('Trader 1: Cancel response ' . json_encode($cancel_response));
                            // ----------------
                            Log::info('Trader 1: Selling at market price');
                            $sell_response = placeSellMarketOrder($this->symbol, $current_quantity, $current_price, $this->trade_acc);
                            Log::info('Trader 1: MARKET SELL Response = ' . json_encode($sell_response));
                            Log::info('Trader 1: Trade closed due to STOP LOSS');
                        }
                    }
                    continue;
                } else {
                    $data = getCandleStickData($this->symbol, $this->interval);

                    $previous_candle = DB::select(
                        'SELECT current_candle, MAX(currentPrice) AS max_price, MIN(currentPrice) AS min_price, MAX(currentPrice) - MIN(currentPrice) AS price_difference, (MAX(currentPrice) - MIN(currentPrice)) / MIN(currentPrice) * 100 AS percentage_increase FROM candlestick_data GROUP BY current_candle ORDER BY current_candle DESC LIMIT 3;',
                        []
                    );

                    $previous_candle = json_decode(json_encode($previous_candle), true);
                    $profit = DB::select('
                        SELECT AVG(percentDiff.percentage_increase) FROM (SELECT current_candle, MAX(currentPrice) AS max_price, MIN(currentPrice) AS min_price, MAX(currentPrice) - MIN(currentPrice) AS price_difference, (MAX(currentPrice) - MIN(currentPrice)) / MIN(currentPrice) * 100 AS percentage_increase FROM candlestick_data GROUP BY current_candle ORDER BY current_candle DESC LIMIT 5) AS percentDiff;
                    ');
                    $indicator_limits = DB::select('SELECT AVG(minValues.currentDEA) AS avg_currentDEA, 
                        AVG(minValues.currentDIF) AS avg_currentDIF, 
                        AVG(minValues.currentPercentageChange) AS avg_currentPercentageChange 
                        FROM (
                            SELECT cd.currentDEA, cd.currentDIF, cd.currentPercentageChange 
                            FROM candlestick_data AS cd
                            JOIN (
                                SELECT current_candle, MIN(currentPrice) AS min_currentPrice
                                FROM candlestick_data
								
                                GROUP BY current_candle
                            ) AS subquery
                        ON cd.current_candle = subquery.current_candle AND cd.currentPrice = subquery.min_currentPrice
                        ORDER BY cd.current_candle DESC
                        LIMIT 3
                        ) AS minValues;');

                    $profit = json_decode(json_encode($profit[0]), true);
                    $indicator_limits = json_decode(json_encode($indicator_limits[0]), true);

                    $this->dif_lim = round(floatval($indicator_limits['avg_currentDIF']), 4);
                    $this->dea_lim = round(floatval($indicator_limits['avg_currentDEA']), 4);
                    $this->per_lim = round(floatval($indicator_limits['avg_currentPercentageChange']), 4);
					
                    $this->estimated_profit = $profit['AVG(percentDiff.percentage_increase)'];
					
                    $scnd_last_plus_percent = $previous_candle[2]['min_price'] * 1.001;
                    $last_candle_highest = $previous_candle[1]['max_price'];

                    Log::info('Trader 1 Data:');
                    Log::info(sprintf(
                        "----------------------------------------------------------------\n" .
                            "| Metric     | Current Value  | Limit Value   |\n" .
                            "----------------------------------------------------------------\n" .
                            "| DIF        | %13.4f  | %12.4f |\n" .
                            "| DEA        | %13.4f  | %12.4f |\n" .
                            "| Percentage | %12.4f %% | %12.4f |\n" .
                            "| PROF LIM   | %13s  | %12.4f |\n" .
                            "----------------------------------------------------------------" .
                            "----------------------------------------------------------------\n" .
                            "| Metric     | Last Candle  | 2nd Last Candle |\n" .
                            "----------------------------------------------------------------\n" .
                            "| Decline    | %13.4f  | %12.4f |\n",
                        round($data['currentDIF'], 4),
                        round($this->dif_lim, 4),
                        round($data['currentDEA'], 4),
                        round($this->dea_lim, 4),
                        round($data['currentPercentageChange'], 4),
                        round($this->per_lim, 4),
                        '',
                        round($this->estimated_profit, 4),
                        $last_candle_highest,
                        $scnd_last_plus_percent
                    ));

                    // Coin Decline condition
                     //if ($last_candle_highest <= $scnd_last_plus_percent) {
                     //    continue;
                     //}
                    if (!empty($current_open_limit_order_buy)) {

                        $createdAt = Carbon::parse($current_open_limit_order_buy->created_at);
                        $timeoutLimit = $this->timeout_limit;
                        $timeoutTimestamp = $createdAt->copy()->addMinutes($timeoutLimit);

                        if (Carbon::now()->greaterThan($timeoutTimestamp)) {
                            Log::info('Trader 1: Cancelling order ');
                            $cancel_response = cancelOrder($this->symbol, $current_open_limit_order_buy->orderId, $this->trade_acc);
                            Log::info('Trader 1: Cancel response ' . json_encode($cancel_response));
                            DB::table('orders')
                                ->where('id', $current_open_limit_order_buy->id)
                                ->update(['trade_status' => 'close']);
                        }
                        $buy_price = $current_open_limit_order_buy->price;
                        $quantity = $current_open_limit_order_buy->qty;

                        $current_order_api = getAllOrders($this->symbol, $this->trade_acc, $current_open_limit_order_buy->orderId);
                        if ($current_order_api['status'] == 'FILLED') {
                            $profitPercentage = $this->estimated_profit;
                            $profitPercentage += 0.1;
                            $target_sell_price = round($buy_price * (1 + $profitPercentage / 100), 4);
                            $sell_response = placeLimitOrder($this->symbol, $quantity, $target_sell_price, 'SELL', $this->trade_acc, $this->buy_coin_price, $this->estimated_profit, $this->stop_limit, $buy_price);
                        } else {
                            Log::info('Trader 1: Current Open Buy Order ' . $current_open_limit_order_buy->orderId);
                            continue;
                        }
                    }

                    // ================================
                    //       Buy (LIMIT)  Logic
                    // ================================

                    if (

                        // Conditions based on DIF and DAE values
                        floatval($data['currentDIF']) <= $this->dif_lim &&
                        floatval($data['currentDEA']) <= $this->dea_lim &&

                        // Conditions based on Percentage
                        floatval($data['currentPercentageChange']) <= $this->per_lim &&

                        // Minimum Profit % Condition
                        $this->estimated_profit >= 0.19

                    ) {

                        $current_price = floatval(getCurrentPrice($this->symbol));
                        $target_buy_price = $current_price * 0.999;
                        $quantity =  $this->buy_coin_price / $target_buy_price;
                        placeLimitOrder($this->symbol, $quantity, $target_buy_price, 'BUY', $this->trade_acc, $this->buy_coin_price, $this->estimated_profit, $this->stop_limit, $target_buy_price);
                    }
                }
                // ==================================
            } catch (\Exception $e) {
                // Log error message
                Log::error('Trader 1: Error occurred while processing ProcessTask job: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
                sendEmailException('Trader 1: Error occurred while processing ProcessTask job: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
                continue;
            }

            // Adding a 500 ms delay for preventing ban
            usleep(500000);
        }
    }
}
