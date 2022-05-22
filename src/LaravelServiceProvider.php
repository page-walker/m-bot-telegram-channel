<?php

namespace PageWalker\MBotTelegramChannel;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PageWalker\MBot\Channels\ChannelManager;
use PageWalker\MBot\Config;
use PageWalker\MBot\HandlerInterface;
use PageWalker\MBot\MBot;
use PageWalker\MBot\Messages\Outgoing\Controls\ButtonStylesInterface;
use PageWalker\MBotTelegramChannel\Channel\TelegramChannel;
use PageWalker\MBotTelegramChannel\Console\Laravel\TelegramLongPollingCommand;
use PageWalker\MBotTelegramChannel\Console\Laravel\TelegramRegisterWebhookCommand;
use PageWalker\MBot\Session\SessionManager;

class LaravelServiceProvider extends ServiceProvider
{
    /**
     * @return void
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \PageWalker\MBot\Exceptions\InvalidDriverNameException
     * @throws \PageWalker\MBot\Exceptions\InvalidNestedConfigException
     */
    public function register()
    {
        $this->registerRoutes();

        $this->registerCommands();

        $this->registerChannelDrivers();
    }

    /**
     * @return void
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \PageWalker\MBot\Exceptions\InvalidDriverNameException
     * @throws \PageWalker\MBot\Exceptions\InvalidNestedConfigException
     */
    public function registerChannelDrivers()
    {
        $channelManager = $this->app->make(ChannelManager::class);

        $channelManager->extend('telegram', function (string $name, Config $config) {
            return new TelegramChannel(
                $name,
                $config,
                $this->app->make(SessionManager::class),
                $this->app->make(HandlerInterface::class),
                $this->app->make(ButtonStylesInterface::class)
            );
        });
    }

    /**
     * Register the command.
     *
     * @return void
     */
    protected function registerCommands()
    {
        $this->commands([
            TelegramLongPollingCommand::class,
            TelegramRegisterWebhookCommand::class,
        ]);
    }

    protected function registerRoutes()
    {
        Route::post('/m-bot/telegram-webhook/{channel}/{token}', function (string $channel, string $token, Request $request) {
            $channel = $this->app[MBot::class]->channel($channel);

            $channel->startFromRequest($token, $request);

            return ['success' => true];
        })->name('m-bot.telegram.webhook');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        //
    }
}
