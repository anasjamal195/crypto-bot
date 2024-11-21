<?php

namespace App\Http\Controllers;

use App\Jobs\CandleStickWorker1;
use App\Jobs\CandleStickWorker2;
use App\Jobs\CandleStickWorker3;
use App\Jobs\TopGainerQueue;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Jobs\Trader1;
use App\Jobs\Trader2;
use App\Mail\CryptoApiMail;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BinanceController extends Controller
{

    public function traderStats($botApiKey, Request $request)
    {
        if ($botApiKey != env("BOT_API_KEY")) {
            return back()->withErrors(['error' => 'Invalid API key']);
        }

        $orders = DB::table('orders')
            ->where('side', 'BUY');

        if ($request->filled('trade_acc'))
            $orders = $orders->where('trade_acc', $_GET['trade_acc']);
        if ($request->filled('start_date'))
            $orders = $orders->where('created_at', '>=', Carbon::parse($_GET['start_date'])->format('Y-m-d H:i:s'));
        if ($request->filled('end_date'))
            $orders = $orders->where('created_at', '<=', Carbon::parse($_GET['end_date'])->format('Y-m-d H:i:s'));
        if ($request->filled('symbol'))
            $orders = $orders->where('symbol', $_GET['symbol']);
        $orders = $orders->orderBy('created_at', 'desc')->get();
        // dd($orders);
        return view('profit-calculator', compact('orders'));
    }

    public function downloadTraderStatsCSV(Request $request)
    {

        $orders = DB::table('orders')->where('side', 'BUY');

        if ($request->filled('trade_acc')) {
            $orders = $orders->where('trade_acc', $request->trade_acc);
        }
        if ($request->filled('start_date')) {
            $orders = $orders->where('created_at', '>=', Carbon::parse($request->start_date)->format('Y-m-d H:i:s'));
        }
        if ($request->filled('end_date')) {
            $orders = $orders->where('created_at', '<=', Carbon::parse($request->end_date)->format('Y-m-d H:i:s'));
        }
        if ($request->filled('symbol')) {
            $orders = $orders->where('symbol', $request->symbol);
        }

        $orders = $orders->orderBy('created_at', 'desc')->get();

        // Return a streamed response to download the CSV
        $response = new StreamedResponse(function () use ($orders) {
            // Open output stream in 'write' mode
            $handle = fopen('php://output', 'w');

            // Add the CSV header
            fputcsv($handle, ['Coin', 'Coin Qty', 'Type', 'Invested USDT', 'Trade Price', 'Fee (BNB)', 'Fee (USDT)', 'Date/Time', 'Profit/Loss', 'Trade Duration (Minutes)']);

            // Loop through each order and write the data to the CSV
            foreach ($orders as $order) {
                $order_sell = DB::table('orders')->where('orderId', $order->pair_id)->first();

                // Skip rows where order_sell is empty
                if (empty($order_sell)) {
                    continue;
                }

                // Calculate profit/loss and trade duration
                $profit = ($order_sell->price * $order_sell->qty) - ($order->price * $order->qty) -
                    ($order->commission * $order->commissionUSDT) - ($order_sell->commission * $order_sell->commissionUSDT);
                $firstTimestamp = Carbon::parse($order->created_at);
                $secondTimestamp = Carbon::parse($order_sell->created_at);
                $durationInMinutes = $firstTimestamp->diffInMinutes($secondTimestamp);

                // Write order data to the CSV
                fputcsv($handle, [
                    $order->symbol,
                    $order->qty,
                    $order->side,
                    $order->price * $order->qty,
                    $order->price,
                    number_format($order->commission, 10),
                    $order->commission * $order->commissionUSDT,
                    $order->created_at,
                    $profit,
                    $durationInMinutes . ' min'
                ]);
            }

            // Close the output stream
            fclose($handle);
        });

        // Set response headers
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="trader_stats.csv"');

        return $response;
    }

    public function autoTrade1(Request $request)
    {
        $botApiKey = $request->query('botApiKey');
        $symbol = $request->query('symbol');
        $interval = $request->query('interval');
        $stop_limit = $request->query('stop_limit');
        $timeout_limit = $request->query('timeout_limit');
        $trade_acc = $request->query('trade_acc');
        $buy_coin_price = $request->query('buy_coin_price');

        if ($botApiKey != env("BOT_API_KEY")) {
            return back()->withErrors(['error' => 'Invalid API key']);
        }

        Trader1::dispatch($symbol, $interval, floatval($stop_limit), floatval($timeout_limit), floatval($buy_coin_price), intval($trade_acc))->onQueue('worker1');

        return back()->with('success', 'Auto Trade 1 started successfully.');
    }

    public function autoTrade2(Request $request)
    {
        $botApiKey = $request->query('botApiKey');
        $symbol = $request->query('symbol');
        $interval = $request->query('interval');
        $stop_limit = $request->query('stop_limit');
        $timeout_limit = $request->query('timeout_limit');
        $trade_acc = $request->query('trade_acc');
        $buy_coin_price = $request->query('buy_coin_price');

        if ($botApiKey != env("BOT_API_KEY")) {
            return back()->withErrors(['error' => 'Invalid API key']);
        }

        Trader2::dispatch($symbol, $interval, floatval($stop_limit), floatval($timeout_limit), floatval($buy_coin_price), intval($trade_acc))->onQueue('worker2');

        return back()->with('success', 'Auto Trade 2 started successfully.');
    }
    public function candleStickWorkerAll(Request $request)
    {
        $botApiKey = $request->query('botApiKey');
        $symbol = '';
        $interval = $request->query('interval');
        $buy_coin_price = $request->query('buy_coin_price');
        $trade_acc = $request->query('trade_acc');

        if ($botApiKey != env("BOT_API_KEY")) {
            return back()->withErrors(['error' => 'Invalid API key']);
        }

        // Disabled for testing on manual coins
        // TopGainerQueue::dispatch()->onQueue('topGainerQueue');
        // CandleStickWorker1::dispatch($symbol, $interval, $buy_coin_price, $trade_acc)->onQueue('candleWorker1');
        // CandleStickWorker2::dispatch($symbol, $interval, $buy_coin_price, $trade_acc)->onQueue('candleWorker2');
        CandleStickWorker3::dispatch($symbol, $interval, $buy_coin_price, $trade_acc)->onQueue('candleWorker3');
        return back()->with('success', 'Candle Stick Workers started successfully on account ' . $trade_acc . '.');
    }

    public function candleStickWorkerReset(Request $request)
    {
        // Navigate to the correct directory and execute the commands
        //     $commands = '
        //       cd /var/www/vhosts/cryptoapis.store/httpdocs/;
        //       killall -9 php;
        //     php artisan queue:clear --queue=candleWorker1; 
        //     php artisan queue:work --queue=candleWorker1 ;


        //   ';

        //     // Execute the commands
        //     exec($commands, $output, $returnCode);
        //     dd($output);
        //     // Check if the execution was successful

        //     if ($returnCode !== 0) {
        //         return back()->withError('success', 'Failed to reset queues.');
        //     }



        return back()->with('success', 'Queues reset successfully.');
    }

    public function candleStickWorker1(Request $request)
    {
        $botApiKey = $request->query('botApiKey');
        $symbol = $request->query('symbol');
        $interval = $request->query('interval');
        $buy_coin_price = $request->query('buy_coin_price');
        $trade_acc = $request->query('trade_acc');

        if ($botApiKey != env("BOT_API_KEY")) {
            return back()->withErrors(['error' => 'Invalid API key']);
        }

        CandleStickWorker1::dispatch($symbol, $interval, $buy_coin_price, $trade_acc)->onQueue('candleWorker1');

        return back()->with('success', 'Candle Stick Worker 1 started successfully.');
    }

    public function candleStickWorker2(Request $request)
    {
        $botApiKey = $request->query('botApiKey');
        $symbol = $request->query('symbol');
        $interval = $request->query('interval');
        $buy_coin_price = $request->query('buy_coin_price');
        $trade_acc = $request->query('trade_acc');

        if ($botApiKey != env("BOT_API_KEY")) {
            return back()->withErrors(['error' => 'Invalid API key']);
        }

        CandleStickWorker2::dispatch($symbol, $interval, $buy_coin_price, $trade_acc)->onQueue('candleWorker2');

        return back()->with('success', 'Candle Stick Worker 2 started successfully.');
    }

    public function candleStickWorker3(Request $request)
    {
        $botApiKey = $request->query('botApiKey');
        $symbol = $request->query('symbol');
        $interval = $request->query('interval');
        $buy_coin_price = $request->query('buy_coin_price');
        $trade_acc = $request->query('trade_acc');

        if ($botApiKey != env("BOT_API_KEY")) {
            return back()->withErrors(['error' => 'Invalid API key']);
        }

        CandleStickWorker3::dispatch($symbol, $interval, $buy_coin_price, $trade_acc)->onQueue('candleWorker3');

        return back()->with('success', 'Candle Stick Worker 3 started successfully.');
    }



    public function restartQueues(Request $request)
    {
        $botApiKey = $request->query('botApiKey');

        if ($botApiKey != env("BOT_API_KEY")) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        $output = [];

        try {
            // Kill all running PHP processes
            $process = new Process(['killall', '-9', 'php']);
            $process->run();
            $output['killall_php'] = $process->getOutput() . $process->getErrorOutput();
            Log::info('Killed all PHP processes. Output: ' . $output['killall_php']);

            // Helper function to clear and restart queues
            $this->clearAndRestartQueue('candleWorker1', $output);
            $this->clearAndRestartQueue('candleWorker2', $output);
            $this->clearAndRestartQueue('worker1', $output); // Changed 'worker4' to 'worker1'
            $this->clearAndRestartQueue('worker2', $output); // Changed 'worker5' to 'worker2'

            return response()->json(['success' => true, 'output' => $output]);
        } catch (\Exception $e) {
            Log::error('Failed to restart queues: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to restart queues', 'message' => $e->getMessage()], 500);
        }
    }

    private function clearAndRestartQueue($queueName, &$output)
    {
        // Clear the queue
        Artisan::call('queue:clear', ['--queue' => $queueName]);
        $output['clear_' . $queueName] = Artisan::output();
        Log::info('Cleared ' . $queueName . ' queue. Output: ' . $output['clear_' . $queueName]);

        // Restart the queue
        $process = new Process(['php', 'artisan', 'queue:work', '--queue=' . $queueName]);
        $process->start();
        $output['restart_' . $queueName] = $process->getOutput() . $process->getErrorOutput();
        Log::info('Restarted ' . $queueName . ' queue. Output: ' . $output['restart_' . $queueName]);
    }

    public function test_route(Request $request)
    {


        // if ($request->filled('symbol') && $request->filled('interval') && $request->filled('limit'))
        //     return getCandleStickDataDownload($request->symbol, $request->interval, $request->limit);

        // DB::table('top_gainers_queue')->truncate();
        // $top_gainers = getTopVolumeCoins(50);
        // foreach ($top_gainers as $gainers) {
        //     DB::table('top_gainers_queue')->insert(['symbol' => $gainers['symbol']]);
        // }
        // dd($top_gainers);


        candlestickDataDumpAllIntervals('BTCUSDT', ['3m'], '100');
        return "All Data Dumped";
        dd("Data Dump Route");

        date_default_timezone_set('Asia/Karachi');  // Set the default timezone to Karachi (Pakistan)

        $candles = getCandleStickDataNew($request->symbol, $request->interval, $request->limit);
        // Create CSV content in memory
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="candlestick_data_' . Carbon::now('Asia/Karachi') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($candles) {
            $file = fopen('php://output', 'w');

            // Add CSV header
            fputcsv($file, [
                'Time',
                'Open',
                'High',
                'Low',
                'Close',
                'Action',
                'Action Price',

            ]);
            $yearly_profit = 0;
            $total_trades = 0;



            $buy_price = 0;
            $buy_time = 0;
            $offered_profit = 0.5;
            $total_profit = 0;







            // HTML output
            $output = '<table border="1" style="border-collapse: collapse;text-align:center;">';
            $output .= '<tr><th style="padding: 8px;">Timestamp</th><th style="padding: 8px;">Open</th><th style="padding: 8px;">High</th><th style="padding: 8px;">Low</th><th style="padding: 8px;">Close</th><th style="padding: 8px;">Action</th><th style="padding: 8px;">Action Price</th><th style="padding: 8px;">Profit</th><th style="padding: 8px;">Interval (mins)</th></tr>';

            foreach ($candles as $index => $candle) {
                $candle['timestamp'] = $candle['timestamp'] / 1000;
                $date = new \DateTime("@{$candle['timestamp']}");  // Create DateTime from Unix timestamp with global namespace prefix
                $date->setTimezone(new \DateTimeZone('Asia/Karachi'));  // Convert timezone to Karachi with global namespace prefix

                if ($index < 5)
                    continue;

                $difference = $candles[$index]['close'] - $candles[$index]['open'] > 0;
                $difference_1 = $candles[$index - 1]['close'] - $candles[$index - 1]['open'] > 0;
                $difference_2 = $candles[$index - 2]['close'] - $candles[$index - 2]['open'] > 0;
                $difference_3 = $candles[$index - 3]['close'] - $candles[$index - 3]['open'] > 0;
                $difference_5 = $candles[$index - 5]['close'] - $candles[$index - 5]['open'] > 0;

                if ($difference_1 && $difference_2 && $difference_3 && $difference_5) {
                    // Buy action
                    $action = 'Buy';
                    $action_price = $candle['close'];
                    $profit_percent = '&nbsp;';  // No profit calculated yet
                    $interval_mins = '&nbsp;';    // No interval calculated yet

                    $output .= '<tr style="background-color:green;color:white;">';
                    $output .= '<td style="padding: 8px;">' . $date->format('d-m-Y H:i:s') . '</td>';
                    $output .= '<td style="padding: 8px;">' . $candle['open'] . '</td>';
                    $output .= '<td style="padding: 8px;">' . $candle['high'] . '</td>';
                    $output .= '<td style="padding: 8px;">' . $candle['low'] . '</td>';
                    $output .= '<td style="padding: 8px;">' . $candle['close'] . '</td>';
                    $output .= '<td style="padding: 8px;"> ' . $action . ' </td>';
                    $output .= '<td style="padding: 8px;">' . $action_price . '</td>';
                    $output .= '<td style="padding: 8px;"> ' . $profit_percent . ' </td>';
                    $output .= '<td style="padding: 8px;"> ' . $interval_mins . ' </td>';
                    $output .= '</tr>';

                    // Record buy price and time
                    $buy_price = $candle['close'];
                    $buy_time = $candle['timestamp'];




                    // CSV Data Dump
                    fputcsv($file, [
                        $date->format('d-m-Y H:i:s'),
                        $candle['open'],
                        $candle['high'],
                        $candle['low'],
                        $candle['close'],
                        $candle['close'],
                        $action,
                        $action_price,
                        '',
                        ''
                    ]);
                } else {
                    // No action
                    $output .= '<tr>';
                    $output .= '<td style="padding: 8px;">' . $date->format('d-m-Y H:i:s') . '</td>';
                    $output .= '<td style="padding: 8px;">' . $candle['open'] . '</td>';
                    $output .= '<td style="padding: 8px;">' . $candle['high'] . '</td>';
                    $output .= '<td style="padding: 8px;">' . $candle['low'] . '</td>';
                    $output .= '<td style="padding: 8px;">' . $candle['close'] . '</td>';
                    $output .= '<td style="padding: 8px;"> &nbsp; </td>';
                    $output .= '<td style="padding: 8px;"> &nbsp; </td>';
                    $output .= '<td style="padding: 8px;"> &nbsp; </td>';
                    $output .= '<td style="padding: 8px;"> &nbsp; </td>';
                    $output .= '</tr>';

                    fputcsv($file, [
                        $date->format('d-m-Y H:i:s'),
                        $candle['open'],
                        $candle['high'],
                        $candle['low'],
                        $candle['close'],
                        $candle['close'],
                        '',
                        '',
                        '',
                        '',
                    ]);
                }
            }

            // Final calculations for output
            $output .= '<tr>';
            $output .= '<td style="padding: 8px;" colspan="6"> Total Profit </td>';
            $output .= '<td style="padding: 8px;"> ' . $total_profit . ' % </td>';
            $output .= '<td style="padding: 8px;"> &nbsp; </td>';
            $yearly_profit += $total_profit;

            $output .= '</table>';
            // echo $output;
            fclose($file);
        };



        // echo "<h1> Net Yearly Return: " . $yearly_profit . " % </h1>";
        // echo "<h1> Net In-Hand Profit: " . ($yearly_profit - (0.15 * $total_trades)) . " % </h1>";
        // echo "<h1> Total Trades: " . $total_trades . " </h1>";
        return response()->stream($callback, 200, $headers);

        exit;
    }

    public function triggerBuyEvent(Request $request)
    {
        try {
            $currentOpenOrderBuy = DB::table('orders')
                ->where('symbol', $request->symbol)
                ->where('side', 'BUY')
                ->where('type', 'MARKET')
                ->where('trade_acc', $request->trade_acc)
                ->where('trade_status', 'open')
                ->orderBy('created_at', 'desc')
                ->first();

            $currentOpenOrderSell = empty($currentOpenOrderBuy) ? [] : DB::table('orders')
                ->where('orderId', $currentOpenOrderBuy->pair_id)
                ->where('trade_acc', $request->trade_acc)
                ->first();

            Log::info('Trader: Buy Event triggered');

            if (!empty($currentOpenOrderSell) && !empty($currentOpenOrderBuy)) {
                Log::info('Trader: Another order is open ' . $currentOpenOrderSell->orderId);

                $currentBuyPrice = $currentOpenOrderBuy->price;
                $currentQuantity = $currentOpenOrderBuy->qty;

                $currentOrderApi = getAllOrders($request->symbol, $request->trade_acc, $currentOpenOrderSell->orderId);

                if ($currentOrderApi['status'] == 'FILLED' || $currentOrderApi['status'] == 'CANCELED') {
                    DB::table('orders')
                        ->where('id', $currentOpenOrderBuy->id)
                        ->update(['trade_status' => 'close']);

                    $orderFillTimestamp = Carbon::createFromTimestamp($currentOrderApi['updateTime'] / 1000)->format('Y-m-d H:i:s');

                    $currentBnb = DB::table('candlestick_data')
                        ->where('symbol', 'BNBUSDT')
                        ->where('created_at', '>=', $orderFillTimestamp)
                        ->orderBy('created_at', 'ASC')
                        ->first() ?? DB::table('candlestick_data')
                        ->where('symbol', 'BNBUSDT')
                        ->orderBy('created_at', 'DESC')
                        ->first();

                    DB::table('orders')
                        ->where('id', $currentOpenOrderSell->id)
                        ->update([
                            'trade_status' => 'close',
                            'status' => $currentOrderApi['status'],
                            'commission' => $currentOpenOrderBuy->commission,
                            'commission_asset' => $currentOpenOrderBuy->commission_asset,
                            'commissionUSDT' => $currentBnb->currentPrice
                        ]);

                    Log::info('Trader 1: Previous Trade Closed');
                } else {
                    $currentPrice = floatval(getCurrentPrice($request->symbol));
                    $stopLimitPercentage = $request->stop_loss;
                    $stopLimitAmount = round($currentBuyPrice * (1 + $stopLimitPercentage / 100), 4);

                    $createdAt = Carbon::parse($currentOpenOrderSell->created_at);
                    $timeoutLimit = 120; // duration in minutes
                    $timeoutTimestamp = $createdAt->copy()->addMinutes($timeoutLimit);

                    $currentProfit = (($currentOpenOrderSell->price - $currentOpenOrderBuy->price) / $currentOpenOrderBuy->price) * 100;
                    $offeredProfit = floatval($request->estimated_profit);

                    // Add your conditional logic here for trade actions

                }
            }

            // Check last order timeout
            $lastOrderSell = DB::table('orders')
                ->where('symbol', $request->symbol)
                ->where('side', 'SELL')
                ->where('type', 'LIMIT')
                ->where('trade_acc', $request->trade_acc)
                ->where('trade_status', 'close')
                ->orderBy('created_at', 'desc')
                ->first();

            $isOrderAllowed = empty($lastOrderSell) ? true : Carbon::now()->greaterThan(Carbon::parse($lastOrderSell->created_at)->copy()->addMinutes(30));

            if (empty($currentOpenOrderSell) && empty($currentOpenOrderBuy) && $isOrderAllowed) {
                $buyResponse = placeBuyOrder(
                    $request->symbol,
                    $request->buy_for_usdt,
                    $request->current_price,
                    $request->trade_acc,
                    $request->estimated_profit,
                    $request->stop_loss,
                    $request->dif_lim,
                    $request->dea_lim,
                    $request->per_lim
                );
                Log::info('Trader: Buy Response: ' . json_encode($buyResponse));

                $sellResponse = placeLimitOrder(
                    $buyResponse['symbol'],
                    $buyResponse['qty'],
                    $buyResponse['target_sell_price'],
                    'SELL',
                    $buyResponse['trade_acc'],
                    $buyResponse['trade_amount'],
                    $buyResponse['target_profit_percentage'],
                    $buyResponse['stop_loss'],
                    $buyResponse['price']
                );
                Log::info('Trader: Sell Response: ' . json_encode($sellResponse));

                // Update Order Pairs
                DB::table('orders')
                    ->where('orderId', $buyResponse['orderId'])
                    ->where('trade_acc', $request->trade_acc)
                    ->update(['pair_id' => $sellResponse['orderId']]);

                DB::table('orders')
                    ->where('orderId', $sellResponse['orderId'])
                    ->where('trade_acc', $request->trade_acc)
                    ->update(['pair_id' => $buyResponse['orderId']]);

                return response()->json([
                    'success' => 'Trade successful',
                    'buy_response' => $buyResponse,
                    'sell_response' => $sellResponse
                ], 200);
            }

            return response()->json(['error' => 'Another order is open or order not allowed'], 400);
        } catch (Exception $e) {
            Log::error('Error in triggerBuyEvent: ' . $e->getMessage());
            return response()->json(['error' => 'An error occurred while processing the trade event'], 500);
        }
    }


    public function updateOrder(Request $request)
    {
        $current_open_order_buy = DB::table('orders')->where('symbol', $request->symbol)->where('side', 'BUY')->where('type', 'MARKET')->where('trade_acc', $request->trade_acc)->where('trade_status', 'open')->orderBy('created_at', 'desc')->first();
        if (empty($current_open_order_buy)) {
            $current_open_order_sell = [];
        } else {
            $current_open_order_sell = DB::table('orders')->where('orderId', $current_open_order_buy->pair_id)->where('trade_acc', $request->trade_acc)->first();
        }

        if (!empty($current_open_order_sell) && !empty($current_open_order_buy)) {


            $current_order_api = getAllOrders($request->symbol, $request->trade_acc, $current_open_order_sell->orderId);

            if ($current_order_api['status'] == 'FILLED' || $current_order_api['status'] == 'CANCELED') {

                DB::table('orders')
                    ->where('id', $current_open_order_buy->id)
                    ->update(['trade_status' => 'close']);
                $order_fill_timestamp = Carbon::createFromTimestamp($current_order_api['updateTime'] / 1000)->format('Y-m-d H:i:s');

                $current_bnb = DB::table('candlestick_data')
                    ->where('symbol', 'BNBUSDT')
                    ->where('created_at', $order_fill_timestamp)
                    ->first();

                if (empty($current_bnb)) {
                    $current_bnb = DB::table('candlestick_data')

                        ->where('symbol', 'BNBUSDT')
                        ->orderBy('created_at', 'DESC')
                        ->first();
                }

                DB::table('orders')
                    ->where('id', $current_open_order_sell->id)
                    ->update(['trade_status' => 'close', 'status' => $current_order_api['status'], 'commission' => $current_open_order_buy->commission, 'commission_asset' => $current_open_order_buy->commission_asset, 'commissionUSDT' => $current_bnb->currentPrice, 'updated_at' => $order_fill_timestamp]);
            }
        }

        return redirect()->back();
    }


    public function buy_market(Request $request)
    {
        $botApiKey = $request->botApiKey;
        if ($botApiKey != env("BOT_API_KEY")) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }
        $lastOrderBuy = DB::table('orders')
            ->where('symbol', $request->symbol)
            ->where('side', 'BUY')
            ->where('type', 'MARKET')
            ->where('trade_acc', $request->trade_acc)
            ->where('trade_status', 'close')
            ->orderBy('created_at', 'desc')
            ->first();

        $lastOrderSell = DB::table('orders')
            ->where('symbol', $request->symbol)
            ->where('side', 'SELL')
            ->where('type', 'MARKET')
            ->where('trade_acc', $request->trade_acc)
            ->where('trade_status', 'close')
            ->orderBy('created_at', 'desc')
            ->first();
        if (isset($lastOrderSell->created_at)) {
            $lastOrderTimestamp = Carbon::parse($lastOrderSell->created_at);  // Ensure it's a Carbon instance and remove milliseconds

            $currentTime = Carbon::now('Asia/Karachi')->toDateTimeString();

            if ($lastOrderTimestamp->diffInMinutes($currentTime)  < 5) {

                return response()->json(['error' => 'Order not permissible on this symbol'], 401);
            }
            if ($lastOrderBuy->price >= $lastOrderSell->price && $lastOrderTimestamp->diffInMinutes($currentTime)  < 45) {

                return response()->json(['error' => 'Order not permissible on this symbol'], 401);
            }
        }

        $buyResponse = placeBuyOrder(
            $request->symbol,
            $request->buy_for_usdt,
            // $request->current_price,
            getCurrentPrice($request->symbol),
            $request->trade_acc,
            $request->estimated_profit,
            $request->stop_loss,
            $request->dif_lim,
            $request->dea_lim,
            $request->per_lim
        );
        Log::info('Trader: Buy Response: ' . json_encode($buyResponse));
        return $buyResponse;
    }


    public function sell_market(Request $request)
    {
        $botApiKey = $request->botApiKey;
        // return  $request->quantity;
        if ($botApiKey != env("BOT_API_KEY")) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }
        $sellResponse = placeSellOrder(
            $request->symbol,
            $request->quantity,
            // $request->current_price,
            getCurrentPrice($request->symbol),
            $request->trade_acc,
            $request->estimated_profit,
            $request->stop_loss,
            $request->dif_lim,
            $request->dea_lim,
            $request->per_lim
        );

        return $sellResponse;
    }
    public function is_order_open(Request $request)
    {
        $botApiKey = $request->botApiKey;
        // return  $request->quantity;
        if ($botApiKey != env("BOT_API_KEY")) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        $open_orders =  DB::table('orders')
            ->where('symbol', $request->symbol)
            ->where('trade_acc', $request->trade_acc)
            ->where('trade_status', 'open')
            ->where('side', 'BUY')
            ->get();
        $open_orders = json_decode(json_encode($open_orders), true);

        if (empty($open_orders)) {
            return response()->json(['is_open' => false], 200);
        } else {
            return response()->json(['is_open' => true, 'order' => $open_orders[0]], 200);
        }
    }



    public function showChart(Request $request)
    {
        // dd(candlestickDataDumpInterval('BTCUSDT','3m','1000',2));

        return view('charts');
    }
    public function fetchChart(Request $request)
    {
        // Validate request inputs
        $validated = $request->validate([
            'symbol' => 'required|string',
            'interval' => 'required|string',
            'limit' => 'sometimes|integer|min:1'
        ]);

        // Set a default limit if not provided
        $limit = $request->input('limit', isset($_GET['limit'])?$_GET['limit']:100); // Default to 500 if no limit is set

        // Fetch data from the database
        $data = DB::table('candlesticks')
            ->select('timestamp', 'open', 'open_predicted')
            ->where('symbol', $validated['symbol'])
            ->where('interval', $validated['interval'])
            ->orderByDesc('timestamp')
            ->limit($limit)
            ->get()
            ->reverse(); // Reverse for chronological order

        // Prepare arrays for the chart
        $labels = $data->pluck('timestamp')->map(function ($timestamp) {
            // Convert timestamps to a more friendly format if necessary
            return $timestamp;
        });
        $openData = $data->pluck('open');
        $openPredictedData = $data->pluck('open_predicted');

        // Return data as JSON
        return response()->json([
            'labels' => $labels,
            'openData' => $openData,
            'openPredictedData' => $openPredictedData
        ]);
    }

    public function startServer(Request $request)
    {
        startPythonServer();
        return redirect()->back();
    }
    public function stopServer(Request $request)
    {
        stopPythonServer();
        return redirect()->back();
    }

    public function startDumper(Request $request)
    {
        return stopPythonServer();
    }


    // Analytics model en
    public function analytics($endpoint, Request $request)
    {
        if ($endpoint == 'startDumper') {
            TopGainerQueue::dispatch()->onQueue('topGainerQueue');
            return redirect()->back();
        }
        // Extract all query parameters from the Laravel HTTP request object
        $queryArgsArr = $request->query();

        // Pass the endpoint and the array of query parameters to the requestPythonModel function
        return requestPythonModel($endpoint, $queryArgsArr);
    }
    public function predictCandles(Request $request)
    {
        $queryArgsArr = $request->query();

        // Pass the endpoint and the array of query parameters to the requestPythonModel function
        $predictions =  json_decode(requestPythonModel('predict', $queryArgsArr), true);
        if ($predictions) {
            foreach ($predictions['predictions'] as $candle) {
                // Insert or update the database entry, allowing duplicates for different intervals or symbols
                DB::table('candlesticks')->updateOrInsert(
                    [
                        'symbol' => $predictions['symbol'],
                        'interval' => $predictions['interval'],
                        'timestamp' => $candle['timestamp'],
                    ],
                    [
                        'open_predicted' => $candle['predicted_price']
                    ]
                );
            }
            return response()->json(['success', 'Predictions added successfully']);
        }
        return response()->json(['error', 'There was an error fetching predictions']);
    }
}
