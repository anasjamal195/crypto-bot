<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TopGainerQueue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $timeout = 360000000;
    /**
     * Create a new job instance.
     */
    public function __construct() {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // while(true){
        //     try {
        //         candlestickDataDumpInterval('BTCUSDT','3m','1000',5);
        //         candlestickDataDumpInterval('BTCUSDT','5m','1000',5);
        //         candlestickDataDumpInterval('BTCUSDT','15m','1000',5);
        //         candlestickDataDumpInterval('BTCUSDT','30m','1000',5);
        //         candlestickDataDumpInterval('BTCUSDT','1h','1000',5);
        //         candlestickDataDumpInterval('BTCUSDT','4h','1000',5);
        //         candlestickDataDumpInterval('BTCUSDT','1d','1000',5);
        //         candlestickDataDumpInterval('BTCUSDT','1w','1000',5);
        //         Log::info('DataDumper: Dataset updated.');
        //     } catch (\Throwable $th) {
        //         Log::error('DataDumper: Error - ' . $th->getMessage());
        //         Log::error($th->getTraceAsString());

        //     }
        // }



        
        candlestickDataDumpAllIntervals('BTCUSDT',['4h'],2190);
        
       

    }
}
