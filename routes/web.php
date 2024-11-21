<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BinanceController;

Route::get('/trader', function () {
    return view('binance');
});

Route::get('/', [BinanceController::class, 'showChart'])->name('showChart');

Route::get('{botApiKey}/trader-stats', [BinanceController::class, 'traderStats'])->name('traderStats');

Route::get('/binance/auto-trade-1', [BinanceController::class, 'autoTrade1'])->name('autoTrade1');

Route::get('/binance/auto-trade-2', [BinanceController::class, 'autoTrade2'])->name('autoTrade2');

Route::get('/binance/candlestick-worker-1', [BinanceController::class, 'candleStickWorker1'])->name('candleStickWorker1');

Route::get('/binance/candlestick-worker-2', [BinanceController::class, 'candleStickWorker2'])->name('candleStickWorker2');

Route::get('/binance/candlestick-worker-3', [BinanceController::class, 'candleStickWorker3'])->name('candleStickWorker3');

Route::get('/binance/candlestick-worker-all', [BinanceController::class, 'candleStickWorkerAll'])->name('candleStickWorkerAll');
Route::get('/binance/candlestick-worker-reset', [BinanceController::class, 'candleStickWorkerReset'])->name('candleStickWorkerReset');

Route::get('/restart-queues', [BinanceController::class, 'restartQueues'])->name('restartQueues');

Route::get('/api-test-route', [BinanceController::class, 'test_route'])->name('testRoute');

Route::post('/trigger-buy-event', [BinanceController::class, 'triggerBuyEvent'])->name('triggerBuyEvent');

Route::post('/update-order', [BinanceController::class, 'updateOrder'])->name('updateOrder');


Route::post('/buy-market', [BinanceController::class, 'buy_market'])->name('buy-market');
Route::post('/sell-market', [BinanceController::class, 'sell_market'])->name('sell-market');
Route::post('/check-open-order', [BinanceController::class, 'is_order_open'])->name('check-open-order');


Route::get('/download-csv', [BinanceController::class, 'downloadTraderStatsCSV'])->name('downloadTraderStatsCSV');


Route::post('/fetchChart', [BinanceController::class, 'fetchChart'])->name('fetchChartData');





// ==========================================
//      Analytics model Api Routes
// ==========================================
Route::get('/analytics/start-server', [BinanceController::class, 'startServer'])->name('start.server');
Route::get('/analytics/stop-server', [BinanceController::class, 'stopServer'])->name('stop.server');
Route::get('/analytics/predict', [BinanceController::class, 'predictCandles'])->name('predict');
Route::get('/analytics/{endpoint}', [BinanceController::class, 'analytics'])->name('analytics');


