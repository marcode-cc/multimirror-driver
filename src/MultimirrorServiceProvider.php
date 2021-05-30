<?php

namespace Multimirror;

use function base_path;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class MultimirrorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(BroadcastManager $broadcastManager)
    {
        $broadcastManager->extend('multimirror', function (Application $app, array $config) {
            return $app->make(MultimirrorBroadcaster::class, [
                'config' => $config,
            ]);
        });
    }
}
