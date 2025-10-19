<?php
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Leantime\Core\Events\EventDispatcher;
use Leantime\Plugins\AuditTrail\Controllers\UiController;

EventDispatcher::add_filter_listener('leantime.core.*.publicActions', 'publicActionsFilter');

function publicActionsFilter($payload, $params){
    $payload[] = "GiteaListener.hook";
    return $payload;
}
