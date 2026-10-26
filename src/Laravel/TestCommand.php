<?php

namespace Zharp\Logs\Laravel;

use Illuminate\Console\Command;
use Zharp\Logs\Zharp;

/**
 * `php artisan zharp:test` — sends one log and says whether Zharp took it.
 */
class TestCommand extends Command
{
    /** @var string */
    protected $signature = 'zharp:test';

    /** @var string */
    protected $description = 'Send a test log to Zharp and check that it arrives';

    public function handle(): int
    {
        if (!Zharp::isActive()) {
            $this->error('Zharp isn\'t running: set ZHARP_API_KEY in .env (an Ingest only key from Integrations & API → Main API keys).');

            return self::FAILURE;
        }
        $service = (string) config('zharp.service');
        Zharp::info('Test log from php artisan zharp:test', ['laravel' => app()->version()]);
        if (!Zharp::flush(10)) {
            $this->error('The test log couldn\'t be sent. Check the network, and that ZHARP_API_KEY is a valid key.');

            return self::FAILURE;
        }
        $this->info("Sent. Open Logs in Zharp and pick the service \"$service\".");
        if (!in_array('zharp', $this->logChannels(), true)) {
            $this->warn('Laravel\'s own logs aren\'t going to Zharp yet: add zharp to your log stack, e.g. LOG_STACK=single,zharp in .env.');
        }

        return self::SUCCESS;
    }

    /** @return string[] The channels the default log channel writes to. */
    private function logChannels(): array
    {
        $default = (string) config('logging.default');
        $channel = (array) config("logging.channels.$default", []);
        if (($channel['driver'] ?? null) !== 'stack') {
            return [$default];
        }
        $channels = $channel['channels'] ?? [];

        return is_array($channels) ? $channels : array_map('trim', explode(',', (string) $channels));
    }
}
